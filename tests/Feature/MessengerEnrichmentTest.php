<?php

namespace Tests\Feature;

use App\Enums\OccupancyStatus;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReport;
use App\Models\Unit;
use App\Models\User;
use App\Services\Messenger\ConversationDirectory;
use App\Support\PrivateFiles;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * غنی‌سازیِ پیام‌رسان (مرحلهٔ ۶ · فاز ۱۴): پاسخ، ویرایش، حذف، واکنش، سنجاق،
 * گزارش، جست‌وجو در گفت‌وگو و پیامِ صوتی — هر کدام با کارکرد و مرزِ امنیتی‌اش.
 */
class MessengerEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $admin;

    private User $alice;

    private User $bob;

    private Unit $unitA;

    private Unit $unitB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::create(['type' => 'residential',
            'name' => 'مجتمع غنی', 'slug' => 'rich-'.uniqid(), 'currency' => 'toman',
            'charge_due_day' => 10, 'payment_gateway' => 'none', 'messenger_enabled' => true,
        ]);

        $this->admin = $this->person('مدیر', '09120000101', UserRole::ComplexAdmin);
        $this->alice = $this->person('آلیس', '09120000102', UserRole::Owner);
        $this->bob = $this->person('باب', '09120000103', UserRole::Tenant);
        $this->unitA = $this->unit('101', $this->alice);
        $this->unitB = $this->unit('102', $this->bob);
    }

    private function person(string $name, string $phone, UserRole $role): User
    {
        return User::create([
            'complex_id' => $this->complex->id, 'name' => $name, 'phone' => $phone,
            'role' => $role, 'password' => Hash::make('secret123'), 'is_active' => true, 'can_message' => true,
        ]);
    }

    private function unit(string $number, User $resident): Unit
    {
        $unit = Unit::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_number' => $number, 'floor' => 1, 'area' => 80,
            'residents_count' => 1, 'coefficient' => 1, 'occupancy_status' => OccupancyStatus::OwnerOccupied,
        ]);

        $resident->units()->attach($unit->id, [
            'complex_id' => $this->complex->id, 'relation' => 'owner', 'share_percent' => 100, 'is_current' => true,
        ]);

        return $unit;
    }

    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);

        return $this->actingAs($user);
    }

    private function thread(Unit $unit): Conversation
    {
        app(TenantContext::class)->set(null);

        return app(ConversationDirectory::class)->unitThread($this->complex, $unit);
    }

    private function channel(): Conversation
    {
        app(TenantContext::class)->set(null);

        return app(ConversationDirectory::class)->channel($this->complex);
    }

    private function url(Conversation $conversation, string $suffix = ''): string
    {
        return "/api/v1/messenger/conversations/{$conversation->id}{$suffix}";
    }

    /** @return int شناسه‌ی پیامِ تازه */
    private function say(User $user, Conversation $conversation, array $payload): int
    {
        return (int) $this->as($user)->postJson($this->url($conversation, '/messages'), $payload)->assertCreated()->json('message.id');
    }

    private function show(User $user, Conversation $conversation): TestResponse
    {
        return $this->as($user)->getJson($this->url($conversation, '/messages'))->assertOk();
    }

    private function find(TestResponse $response, int $id): array
    {
        return collect($response->json('messages'))->firstWhere('id', $id);
    }

    private function body(TestResponse $response): string
    {
        return (string) json_encode($response->json(), JSON_UNESCAPED_UNICODE);
    }

    /* ─────────────────────────── هم‌رسانیِ تغییرها ─────────────────────────── */

    /**
     * 🔴 ویرایش، واکنش و حذفِ طرفِ مقابل با همان پرسشِ دوره‌ای می‌رسد.
     *
     * `since` فقط پیامِ تازه می‌آورد؛ بی‌`changed` کلاینتِ دیگر متنِ قدیمی یا
     * پیامِ حذف‌شده را تا بارگذاریِ دوباره نشان می‌داد.
     */
    public function test_edits_reactions_and_deletions_reach_the_other_side_by_polling(): void
    {
        $thread = $this->thread($this->unitA);
        $id = $this->say($this->alice, $thread, ['body' => 'سلم']);

        /*
         * ⚠️ هر گام خط‌پایه‌ی تازه‌ی خودش را دارد، بیرون از هم‌پوشانیِ دوثانیه‌ای.
         * نسخه‌ی اولِ این تست یک خط‌پایه داشت: ویرایشِ گامِ قبل پیام را در
         * «تغییرها» نگه می‌داشت و خرابکاری نشان داد لمس‌نکردنِ پیام هنگامِ واکنش
         * (و پاسخ‌ها هنگامِ حذف) هرگز قرمز نمی‌شد.
         */
        $baseline = function () use ($thread): callable {
            $this->travel(5)->seconds();
            $view = $this->show($this->admin, $thread);
            $since = collect($view->json('messages'))->max('id');
            $after = $view->json('serverTime') - 2;
            $this->travel(5)->seconds();

            return fn () => collect($this->as($this->admin)
                ->getJson($this->url($thread, "/messages?since={$since}&changed_after={$after}"))
                ->assertOk()->json('changed'))->keyBy('id');
        };

        $poll = $baseline();
        $this->assertSame([], $poll()->all(), 'بی‌تغییر، چیزی «تغییرکرده» نیامد؟');

        $poll = $baseline();
        $this->as($this->alice)->patchJson($this->url($thread, "/messages/{$id}"), ['body' => 'سلام'])->assertOk();
        $this->assertSame('سلام', $poll()[$id]['body'] ?? null);

        $poll = $baseline();
        $this->as($this->alice)->postJson($this->url($thread, "/messages/{$id}/reactions"), ['emoji' => '🙏'])->assertOk();
        $this->assertSame('🙏', $poll()[$id]['reactions'][0]['emoji'] ?? null, 'واکنش در «تغییرها» نیامد.');

        $poll = $baseline();
        $this->as($this->alice)->deleteJson($this->url($thread, "/messages/{$id}"))->assertOk();
        $this->assertTrue($poll()[$id]['isDeleted'] ?? false);

        // 🔴 پاسخی که پیامِ حذف‌شده را نقل کرده بود هم می‌آید — بی‌متنِ پاک‌شده
        $quoted = $this->say($this->alice, $thread, ['body' => 'متنی که حذف خواهد شد']);
        $reply = $this->say($this->admin, $thread, ['body' => 'پاسخ', 'reply_to_id' => $quoted]);
        $poll = $baseline();
        $this->as($this->alice)->deleteJson($this->url($thread, "/messages/{$quoted}"))->assertOk();

        $changed = $poll();
        $this->assertTrue($changed->has($reply), 'پاسخِ نقل‌کننده در «تغییرها» نیامد.');
        $this->assertNull($changed[$reply]['replyTo']['excerpt']);
        $this->assertTrue($changed[$reply]['replyTo']['isDeleted']);

        // بی‌`changed_after` همان پاسخِ قبلی — کلاینتِ قدیم نمی‌شکند
        $this->assertSame([], $this->as($this->admin)->getJson($this->url($thread, '/messages?since=1'))->json('changed'));
    }

    /* ─────────────────────────── پاسخ ─────────────────────────── */

    public function test_a_reply_quotes_its_target_from_the_same_conversation(): void
    {
        $thread = $this->thread($this->unitA);
        $question = $this->say($this->alice, $thread, ['body' => 'آسانسور خراب است']);
        $answer = $this->say($this->admin, $thread, ['body' => 'فردا تعمیرکار می‌آید', 'reply_to_id' => $question]);

        $this->assertSame(
            ['id' => $question, 'authorName' => 'آلیس', 'excerpt' => 'آسانسور خراب است', 'isDeleted' => false],
            $this->find($this->show($this->alice, $thread), $answer)['replyTo'],
        );
    }

    /** 🔴 پاسخ راهِ دیدنِ پیامِ گفت‌وگوی دیگر یا پیامِ پنهان نیست. */
    public function test_a_reply_cannot_reach_outside_what_the_writer_can_see(): void
    {
        $bobsSecret = $this->say($this->bob, $this->thread($this->unitB), ['body' => 'رازِ واحدِ ۱۰۲']);

        $leak = $this->as($this->alice)->postJson($this->url($this->thread($this->unitA), '/messages'), ['body' => 'x', 'reply_to_id' => $bobsSecret]);
        $leak->assertStatus(422)->assertJsonPath('code', 'messenger.reply_unavailable');
        $this->assertStringNotContainsString('رازِ', $this->body($leak));

        $thread = $this->thread($this->unitA);
        $rude = $this->say($this->admin, $thread, ['body' => 'متنِ نامناسب']);
        Message::whereKey($rude)->update(['is_hidden' => true]);

        $this->as($this->alice)->postJson($this->url($thread, '/messages'), ['body' => 'x', 'reply_to_id' => $rude])
            ->assertStatus(422)->assertJsonPath('code', 'messenger.reply_unavailable');
    }

    /** پاسخی که پیش از پنهان‌شدنِ هدف نوشته شد، پس از آن متنِ هدف را به ساکن نشان نمی‌دهد. */
    public function test_a_reply_stops_quoting_a_target_that_was_hidden_later(): void
    {
        $thread = $this->thread($this->unitA);
        $target = $this->say($this->alice, $thread, ['body' => 'متنی که بعداً پنهان شد']);
        $reply = $this->say($this->admin, $thread, ['body' => 'پاسخ', 'reply_to_id' => $target]);

        Message::whereKey($target)->update(['is_hidden' => true]);

        $resident = $this->show($this->alice, $thread);
        $this->assertNull($this->find($resident, $reply)['replyTo']['excerpt']);
        $this->assertStringNotContainsString('بعداً پنهان', $this->body($resident));

        $this->assertSame('متنی که بعداً پنهان شد', $this->find($this->show($this->admin, $thread), $reply)['replyTo']['excerpt']);
    }

    /* ─────────────────────────── ویرایش ─────────────────────────── */

    public function test_the_author_edits_within_the_window_and_the_mark_stays(): void
    {
        $thread = $this->thread($this->unitA);
        $id = $this->say($this->alice, $thread, ['body' => 'سلم']);

        $edited = $this->as($this->alice)->patchJson($this->url($thread, "/messages/{$id}"), ['body' => 'سلام'])->assertOk();
        $this->assertSame('سلام', $edited->json('message.body'));
        $this->assertTrue($edited->json('message.isEdited'));

        $this->travel(16)->minutes();
        $this->as($this->alice)->patchJson($this->url($thread, "/messages/{$id}"), ['body' => 'دیر'])
            ->assertForbidden()->assertJsonPath('code', 'messenger.edit_window');
        $this->assertFalse($this->find($this->show($this->alice, $thread), $id)['canEdit']);
    }

    /** نه پیامِ دیگری، نه مدیر روی پیامِ ساکن، نه ساکنِ محدودشده، نه پیامِ پنهان‌شده. */
    public function test_nobody_edits_what_is_not_theirs_to_edit(): void
    {
        $thread = $this->thread($this->unitA);
        $alices = $this->say($this->alice, $thread, ['body' => 'متنِ آلیس']);

        $this->as($this->admin)->patchJson($this->url($thread, "/messages/{$alices}"), ['body' => 'دست‌کاری'])
            ->assertForbidden()->assertJsonPath('code', 'messenger.not_yours');

        Message::whereKey($alices)->update(['is_hidden' => true]);
        $this->as($this->alice)->patchJson($this->url($thread, "/messages/{$alices}"), ['body' => 'پاک‌کردنِ سابقه'])
            ->assertForbidden()->assertJsonPath('code', 'messenger.moderated');

        $another = $this->say($this->alice, $thread, ['body' => 'دوم']);
        $this->alice->forceFill(['can_message' => false])->save();
        $this->as($this->alice)->patchJson($this->url($thread, "/messages/{$another}"), ['body' => 'x'])->assertForbidden();

        $this->assertSame('متنِ آلیس', Message::find($alices)->body);
    }

    /* ─────────────────────────── حذف ─────────────────────────── */

    /** 🔴 حذف یعنی محتوا واقعاً رفته: متن، فایلِ پیوست، واکنش‌ها و سنجاق. */
    public function test_deleting_removes_the_content_not_just_the_view(): void
    {
        Storage::fake(PrivateFiles::name());
        $thread = $this->thread($this->unitA);

        $this->as($this->alice)->post($this->url($thread, '/messages'), [
            'body' => 'عکسِ نشتی', 'attachment' => UploadedFile::fake()->image('leak.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $message = Message::latest('id')->first();
        $path = $message->attachment_path;
        Storage::disk(PrivateFiles::name())->assertExists($path);

        $this->as($this->admin)->postJson($this->url($thread, "/messages/{$message->id}/reactions"), ['emoji' => '👍'])->assertOk();
        $this->as($this->admin)->postJson($this->url($thread, '/pin'), ['message_id' => $message->id])->assertOk();

        $deleted = $this->as($this->alice)->deleteJson($this->url($thread, "/messages/{$message->id}"))->assertOk();
        $this->assertTrue($deleted->json('message.isDeleted'));
        $this->assertNull($deleted->json('message.body'));
        $this->assertNull($deleted->json('message.attachment'));

        Storage::disk(PrivateFiles::name())->assertMissing($path);
        $this->assertSame('', Message::find($message->id)->body);
        $this->assertSame([], $deleted->json('message.reactions'));

        // مدیر هم محتوا را نمی‌بیند — واقعاً رفته
        $admin = $this->show($this->admin, $thread);
        $this->assertStringNotContainsString('عکسِ نشتی', $this->body($admin));
        $this->assertNull($admin->json('pinned'));

        $list = collect($this->as($this->admin)->getJson('/api/v1/messenger/conversations')->json('data'))->firstWhere('id', $thread->id);
        $this->assertSame('این پیام حذف شد', $list['lastMessage']['preview']);
        $this->assertSame(0, $list['unreadCount'], 'پیامِ حذف‌شده «نخوانده» شمرده شد.');
    }

    public function test_only_the_author_deletes_and_never_a_poll_or_moderated_message(): void
    {
        $thread = $this->thread($this->unitA);
        $alices = $this->say($this->alice, $thread, ['body' => 'متنِ آلیس']);

        $this->as($this->admin)->deleteJson($this->url($thread, "/messages/{$alices}"))
            ->assertForbidden()->assertJsonPath('code', 'messenger.not_yours');

        $poll = $this->say($this->admin, $thread, ['poll_question' => 'رنگِ لابی؟', 'poll_options' => ['سفید', 'کرم']]);
        $this->as($this->admin)->deleteJson($this->url($thread, "/messages/{$poll}"))
            ->assertStatus(409)->assertJsonPath('code', 'messenger.poll_message');

        Message::whereKey($alices)->update(['is_hidden' => true]);
        $this->as($this->alice)->deleteJson($this->url($thread, "/messages/{$alices}"))
            ->assertForbidden()->assertJsonPath('code', 'messenger.moderated');
    }

    /* ─────────────────────────── واکنش ─────────────────────────── */

    public function test_reactions_toggle_and_replace_and_only_counts_are_shown(): void
    {
        $channel = $this->channel();
        $id = $this->say($this->admin, $channel, ['body' => 'جلسه‌ی ماهانه پنجشنبه']);
        $react = fn (User $u, string $e) => $this->as($u)->postJson($this->url($channel, "/messages/{$id}/reactions"), ['emoji' => $e]);

        // ساکن در کانال نمی‌نویسد ولی واکنش می‌دهد
        $react($this->alice, '👍')->assertOk();
        $react($this->bob, '👍')->assertOk();
        // واکنشِ دیگر جای قبلی را می‌گیرد
        $react($this->bob, '❤️')->assertOk();

        $this->assertSame(
            [['emoji' => '👍', 'count' => 1, 'mine' => true], ['emoji' => '❤️', 'count' => 1, 'mine' => false]],
            $this->find($this->show($this->alice, $channel), $id)['reactions'],
        );

        // همان واکنش دوباره = برداشتن
        $react($this->alice, '👍')->assertOk();
        $this->assertSame([['emoji' => '❤️', 'count' => 1, 'mine' => false]], $this->find($this->show($this->alice, $channel), $id)['reactions']);

        // فقط از فهرستِ ثابت
        $react($this->alice, '<b>')->assertStatus(422);
        $react($this->alice, 'بد')->assertStatus(422);
    }

    public function test_reactions_follow_visibility_and_moderation(): void
    {
        $bobsThread = $this->thread($this->unitB);
        $bobs = $this->say($this->bob, $bobsThread, ['body' => 'خصوصی']);

        // گفت‌وگوی واحدِ دیگر
        $this->as($this->alice)->postJson($this->url($bobsThread, "/messages/{$bobs}/reactions"), ['emoji' => '👍'])->assertNotFound();

        // 🔴 پیامِ گفت‌وگوی دیگر زیرِ گفت‌وگوی خودم — scopeBindings
        $this->as($this->alice)->postJson($this->url($this->thread($this->unitA), "/messages/{$bobs}/reactions"), ['emoji' => '👍'])->assertNotFound();

        $thread = $this->thread($this->unitA);
        $hidden = $this->say($this->admin, $thread, ['body' => 'پنهان']);
        Message::whereKey($hidden)->update(['is_hidden' => true]);
        $this->as($this->alice)->postJson($this->url($thread, "/messages/{$hidden}/reactions"), ['emoji' => '👍'])
            ->assertStatus(422)->assertJsonPath('code', 'messenger.unavailable');

        $open = $this->say($this->admin, $thread, ['body' => 'باز']);
        $this->alice->forceFill(['can_message' => false])->save();
        $this->as($this->alice)->postJson($this->url($thread, "/messages/{$open}/reactions"), ['emoji' => '👍'])->assertForbidden();
    }

    /* ─────────────────────────── سنجاق ─────────────────────────── */

    public function test_management_pins_one_message_per_conversation(): void
    {
        $channel = $this->channel();
        $id = $this->say($this->admin, $channel, ['body' => 'قوانینِ پارکینگ']);

        $this->as($this->alice)->postJson($this->url($channel, '/pin'), ['message_id' => $id])->assertForbidden();

        $this->as($this->admin)->postJson($this->url($channel, '/pin'), ['message_id' => $id])->assertOk();
        $this->assertSame($id, $this->show($this->alice, $channel)->json('pinned.id'));

        // پیامِ گفت‌وگوی دیگر سنجاقِ این گفت‌وگو نمی‌شود
        $elsewhere = $this->say($this->bob, $this->thread($this->unitB), ['body' => 'خصوصیِ باب']);
        $this->as($this->admin)->postJson($this->url($channel, '/pin'), ['message_id' => $elsewhere])->assertNotFound();

        // سنجاقی که پنهان شد، برای ساکن سنجاق نیست
        Message::whereKey($id)->update(['is_hidden' => true]);
        $this->assertNull($this->show($this->alice, $channel)->json('pinned'));

        $this->as($this->admin)->postJson($this->url($channel, '/pin'), ['message_id' => null])->assertOk();
        $this->assertNull($this->show($this->admin, $channel)->json('pinned'));
    }

    /* ─────────────────────────── گزارش ─────────────────────────── */

    public function test_a_resident_reports_and_management_resolves_without_seeing_who(): void
    {
        $group = app(ConversationDirectory::class)->groupForUnits($this->complex, [$this->unitA->id, $this->unitB->id], $this->admin);
        $group->update(['members_can_post' => true]);
        $bobs = $this->say($this->bob, $group, ['body' => 'توهین']);

        $this->as($this->alice)->postJson($this->url($group, "/messages/{$bobs}/report"), ['reason' => 'بی‌احترامی'])->assertCreated();
        $this->as($this->alice)->postJson($this->url($group, "/messages/{$bobs}/report"), ['reason' => 'دوباره'])->assertOk();
        $this->assertSame(1, MessageReport::withoutGlobalScopes()->count());

        // خودش، مدیر
        $this->as($this->bob)->postJson($this->url($group, "/messages/{$bobs}/report"))->assertStatus(422);
        $this->as($this->admin)->postJson($this->url($group, "/messages/{$bobs}/report"))->assertForbidden();

        // ساکن صفِ گزارش را نمی‌بیند
        $this->as($this->alice)->getJson('/api/v1/messenger/reports')->assertForbidden();

        $queue = $this->as($this->admin)->getJson('/api/v1/messenger/reports')->assertOk();
        $this->assertSame(1, $queue->json('openCount'));
        $this->assertSame(['بی‌احترامی'], $queue->json('data.0.reasons'));
        $this->assertStringNotContainsString('آلیس', $this->body($queue), 'نامِ گزارش‌دهنده به مدیر رسید.');

        $report = $queue->json('data.0.id');
        $this->as($this->alice)->postJson("/api/v1/messenger/reports/{$report}/resolve", ['resolution' => 'hidden'])->assertForbidden();
        $this->as($this->admin)->postJson("/api/v1/messenger/reports/{$report}/resolve", ['resolution' => 'hidden'])->assertOk();

        $this->assertTrue(Message::find($bobs)->is_hidden);
        $this->assertSame(0, $this->as($this->admin)->getJson('/api/v1/messenger/reports')->json('openCount'));
    }

    /* ─────────────────────────── جست‌وجو در گفت‌وگو ─────────────────────────── */

    public function test_search_in_a_conversation_respects_hiding_deleting_and_visibility(): void
    {
        $thread = $this->thread($this->unitA);
        $this->say($this->alice, $thread, ['body' => 'کلیدِ انباری گم شد']);
        $hidden = $this->say($this->admin, $thread, ['body' => 'کلیدِ پنهان']);
        Message::whereKey($hidden)->update(['is_hidden' => true]);
        $gone = $this->say($this->alice, $thread, ['body' => 'کلیدِ حذفی']);
        $this->as($this->alice)->deleteJson($this->url($thread, "/messages/{$gone}"))->assertOk();

        $resident = $this->as($this->alice)->getJson($this->url($thread, '/search?q='.urlencode('کلیدِ')))->assertOk();
        $this->assertSame(['کلیدِ انباری گم شد'], collect($resident->json('results'))->pluck('excerpt')->all());

        $this->assertCount(2, $this->as($this->admin)->getJson($this->url($thread, '/search?q='.urlencode('کلیدِ')))->json('results'));

        $this->as($this->bob)->getJson($this->url($thread, '/search?q='.urlencode('کلیدِ')))->assertNotFound();
        $this->assertSame([], $this->as($this->alice)->getJson($this->url($thread, '/search?q=%25'))->json('results'));
    }

    /* ─────────────────────────── پیامِ صوتی ─────────────────────────── */

    public function test_a_voice_message_is_stored_and_served_as_audio(): void
    {
        Storage::fake(PrivateFiles::name());
        $thread = $this->thread($this->unitA);

        $sent = $this->as($this->alice)->post($this->url($thread, '/messages'), [
            'voice' => UploadedFile::fake()->create('voice.webm', 40, 'audio/webm'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame('audio', $sent->json('message.attachment.kind'));

        $served = $this->as($this->admin)->get($sent->json('message.attachment.url'))->assertOk();
        $this->assertStringStartsWith('audio/', (string) $served->headers->get('Content-Type'));
        $this->assertSame('nosniff', $served->headers->get('X-Content-Type-Options'));

        $list = collect($this->as($this->admin)->getJson('/api/v1/messenger/conversations')->json('data'))->firstWhere('id', $thread->id);
        $this->assertSame('پیامِ صوتی', $list['lastMessage']['preview']);
    }

    public function test_a_voice_message_accepts_only_audio_of_bounded_size(): void
    {
        Storage::fake(PrivateFiles::name());
        $thread = $this->thread($this->unitA);
        $post = fn (array $data) => $this->as($this->alice)->post($this->url($thread, '/messages'), $data, ['Accept' => 'application/json']);

        $post(['voice' => UploadedFile::fake()->create('evil.html', 5, 'text/html')])->assertStatus(422);
        $post(['voice' => UploadedFile::fake()->create('long.webm', 2048, 'audio/webm')])->assertStatus(422);
        $post([
            'voice' => UploadedFile::fake()->create('v.webm', 10, 'audio/webm'),
            'attachment' => UploadedFile::fake()->image('p.jpg'),
        ])->assertStatus(422);

        // «voice» فقط از فیلدِ خودش: پیوستِ عادی هرگز `audio` نمی‌شود
        $post(['attachment' => UploadedFile::fake()->create('a.pdf', 5, 'application/pdf')])->assertCreated();
        $this->assertSame('file', Message::latest('id')->first()->attachment_kind);
    }

    /* ─────────────────────────── XSS ─────────────────────────── */

    /** متن همان است که نوشته شد، به‌صورتِ رشته — HTML ساخته نمی‌شود؛ React هنگامِ نمایش escape می‌کند. */
    public function test_markup_in_a_message_stays_plain_text_everywhere(): void
    {
        $thread = $this->thread($this->unitA);
        $payload = '<img src=x onerror=alert(1)>';
        $id = $this->say($this->alice, $thread, ['body' => $payload]);
        $reply = $this->say($this->admin, $thread, ['body' => 'ok', 'reply_to_id' => $id]);

        $view = $this->show($this->admin, $thread);
        $this->assertSame($payload, $this->find($view, $id)['body']);
        $this->assertSame($payload, $this->find($view, $reply)['replyTo']['excerpt']);
        $this->assertStringContainsString('application/json', (string) $view->headers->get('Content-Type'));
    }
}
