<?php

namespace Tests\Feature;

use App\Enums\OccupancyStatus;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Unit;
use App\Models\User;
use App\Services\Messenger\ConversationBackfill;
use App\Services\Messenger\ConversationDirectory;
use App\Services\Messenger\MessageSender;
use App\Services\Messenger\MessagingAccess;
use App\Support\PrivateFiles;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * پیام‌رسانِ گفت‌وگومحور (مرحلهٔ ۶ · فاز ۱۲): کارکرد، مهاجرتِ داده و
 * سناریوهای امنیتی.
 */
class MessengerConversationTest extends TestCase
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
            'name' => 'مجتمع گفت‌وگو', 'slug' => 'chat-'.uniqid(), 'currency' => 'toman',
            'charge_due_day' => 10, 'payment_gateway' => 'none', 'messenger_enabled' => true,
        ]);

        $this->admin = $this->person('مدیر', '09120000071', UserRole::ComplexAdmin);
        $this->alice = $this->person('آلیس', '09120000072', UserRole::Owner);
        $this->bob = $this->person('باب', '09120000073', UserRole::Tenant);

        $this->unitA = $this->unit('101', $this->alice);
        $this->unitB = $this->unit('102', $this->bob);
    }

    private function person(string $name, string $phone, UserRole $role): User
    {
        return User::create([
            'complex_id' => $this->complex->id, 'name' => $name, 'phone' => $phone,
            'role' => $role, 'password' => Hash::make('secret123'), 'is_active' => true,
            // ⚠️ صریح: نمونه‌ی حافظه‌ای پیش‌فرضِ دیتابیس را ندارد (همان نکته‌ی MessengerFeaturesTest)
            'can_message' => true,
        ]);
    }

    private function unit(string $number, User $resident, bool $current = true): Unit
    {
        $unit = Unit::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_number' => $number, 'floor' => 1, 'area' => 80,
            'residents_count' => 1, 'coefficient' => 1, 'occupancy_status' => OccupancyStatus::OwnerOccupied,
        ]);

        $resident->units()->attach($unit->id, [
            'complex_id' => $this->complex->id, 'relation' => 'owner', 'share_percent' => 100, 'is_current' => $current,
        ]);

        return $unit;
    }

    /** هر درخواست با زمینه‌ی مستأجرِ تازه، مثلِ محصول. */
    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);

        return $this->actingAs($user);
    }

    /**
     * گفت‌وگوی خصوصیِ ساکنِ این واحد با مدیریت. ⚠️ ۱۰/۹: پیش از این رشته‌ی مشترکِ **واحد** بود (`archive()`)؛ حالا
     * هر عضو گفت‌وگوی خودش را دارد (بندِ ۳۷) و قاعده‌های این فایل روی همان سنجیده می‌شوند.
     */
    private function thread(Unit $unit): Conversation
    {
        $resident = $unit->is($this->unitA) ? $this->alice : $this->bob;

        return Conversation::withoutGlobalScopes()->firstOrCreate(
            ['complex_id' => $this->complex->id, 'key' => 'user:'.$resident->id],
            ['type' => 'user', 'user_id' => $resident->id],
        );
    }

    /** بایگانیِ رشته‌ی مشترکِ واحد از پیش از ۱۰/۹ — فقط‌خواندنی؛ پیامش مستقیم، مثلِ دادهٔ قدیم. */
    private function archive(Unit $unit, User $author, string $body): Conversation
    {
        $archive = Conversation::withoutGlobalScopes()->firstOrCreate(
            ['complex_id' => $this->complex->id, 'key' => 'unit:'.$unit->id],
            ['type' => 'unit', 'unit_id' => $unit->id],
        );
        $id = DB::table('messages')->insertGetId([
            'complex_id' => $this->complex->id, 'conversation_id' => $archive->id, 'user_id' => $author->id, 'body' => $body,
            'audience' => 'management', 'unit_id' => $unit->id, 'author_name' => $author->name, 'is_hidden' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $archive->forceFill(['last_message_id' => $id])->save();

        return $archive;
    }

    /**
     * ⚠️ `assertSee` روی JSON پوچ است: لاراول فارسی را `\u06..` می‌نویسد، پس
     * `assertDontSee('متن')` همیشه سبز می‌ماند. متنِ رمزگشایی‌شده سنجیده می‌شود.
     */
    private function body(TestResponse $response): string
    {
        return (string) json_encode($response->json(), JSON_UNESCAPED_UNICODE);
    }

    private function send(User $user, Conversation $conversation, array $payload): TestResponse
    {
        return $this->as($user)->postJson("/api/v1/messenger/conversations/{$conversation->id}/messages", $payload);
    }

    /* ─────────────────────────── کارکرد ─────────────────────────── */

    /** سفرِ کامل: ساکن می‌نویسد، مدیر می‌بیند و نخوانده دارد، می‌خواند، ساکن «خوانده شد» می‌بیند. */
    public function test_a_resident_writes_to_management_and_sees_it_read(): void
    {
        $list = $this->as($this->alice)->getJson('/api/v1/messenger/conversations')->assertOk();
        $types = collect($list->json('data'))->pluck('type')->all();
        // ۱۰/۹ (بندِ ۴۰): گروهِ مجتمع، گفت‌وگوی خودش با مدیریت و «ذخیره‌شده» — واحدِ بی‌مستأجر «مالک ↔ مستأجر» ندارد
        $this->assertEqualsCanonicalizing(['channel', 'user', 'saved'], $types, 'ساکن گروه، مدیریت و ذخیره‌شده را از همان بارِ اول دارد.');

        $thread = collect($list->json('data'))->firstWhere('type', 'user');
        $this->assertSame('مدیریتِ ساختمان', $thread['title']);

        $sent = $this->as($this->alice)->postJson("/api/v1/messenger/conversations/{$thread['id']}/messages", [
            'body' => 'آسانسور خراب است', 'client_id' => 'c-1',
        ])->assertCreated();
        $this->assertSame('sent', $sent->json('message.status'));

        $adminList = $this->as($this->admin)->getJson('/api/v1/messenger/conversations')->json('data');
        $row = collect($adminList)->firstWhere('id', $thread['id']);
        $this->assertSame('آلیس', $row['title']);
        $this->assertSame(1, $row['unreadCount']);
        $this->assertSame('آسانسور خراب است', $row['lastMessage']['preview']);

        $this->as($this->admin)->postJson("/api/v1/messenger/conversations/{$thread['id']}/read", ['up_to' => $sent->json('message.id')])
            ->assertOk()->assertJsonPath('marked', 1);
        $this->assertSame(0, collect($this->as($this->admin)->getJson('/api/v1/messenger/conversations')->json('data'))->firstWhere('id', $thread['id'])['unreadCount']);

        $mine = $this->as($this->alice)->getJson("/api/v1/messenger/conversations/{$thread['id']}/messages");
        $this->assertSame('read', $mine->json('messages.0.status'));

        /*
         * 🔴 و برای پیامی که **از قبل** در کلاینت است: پرسشِ `since` خالی است ولی
         * `readUpTo` می‌گوید تا کجا خوانده شده (آزمونِ مرورگر این شکاف را یافت).
         */
        $since = $this->as($this->alice)->getJson("/api/v1/messenger/conversations/{$thread['id']}/messages?since=".$sent->json('message.id'));
        $this->assertSame([], $since->json('messages'));
        $this->assertSame($sent->json('message.id'), $since->json('readUpTo'));
    }

    /** تاریخچه با نشانگر: هیچ پیامی دو بار یا هیچ بار نمی‌آید، حتی با پیامِ تازه در میانه. */
    public function test_history_pages_by_cursor_without_gaps_or_repeats(): void
    {
        $thread = $this->thread($this->unitA);
        // از خودِ سرویس، نه HTTP: سقفِ نرخِ ارسال واقعی است و این‌جا موضوع نیست
        $sender = app(MessageSender::class);
        foreach (range(1, 45) as $i) {
            $sender->send($this->complex, $thread, $this->alice, ['body' => "پیام {$i}"]);
        }

        $first = $this->as($this->alice)->getJson("/api/v1/messenger/conversations/{$thread->id}/messages")->assertOk();
        $this->assertCount(40, $first->json('messages'));
        $this->assertTrue($first->json('hasOlder'));
        $this->assertSame('پیام 45', collect($first->json('messages'))->last()['body'], 'تازه‌ترین پایینِ صفحه است.');

        // پیامِ تازه میانِ دو درخواست — صفحه‌ی بعد جابه‌جا نمی‌شود
        $sender->send($this->complex, $thread, $this->alice, ['body' => 'پیام ۴۶']);

        $older = $this->as($this->alice)
            ->getJson("/api/v1/messenger/conversations/{$thread->id}/messages?before=".$first->json('nextCursor'))
            ->assertOk();
        $this->assertSame(['پیام 1', 'پیام 2', 'پیام 3', 'پیام 4', 'پیام 5'], collect($older->json('messages'))->pluck('body')->all());
        $this->assertFalse($older->json('hasOlder'));

        $newest = $this->as($this->alice)
            ->getJson("/api/v1/messenger/conversations/{$thread->id}/messages?since=".collect($first->json('messages'))->last()['id'])
            ->json('messages');
        $this->assertSame(['پیام ۴۶'], collect($newest)->pluck('body')->all());
    }

    /** فهرست به ترتیبِ آخرین پیام؛ گفت‌وگویی که پیام می‌گیرد بالا می‌رود. */
    public function test_conversations_are_ordered_by_their_latest_message(): void
    {
        $a = $this->thread($this->unitA);
        $b = $this->thread($this->unitB);
        $this->send($this->alice, $a, ['body' => 'اول']);
        $this->send($this->bob, $b, ['body' => 'دوم']);

        $ids = collect($this->as($this->admin)->getJson('/api/v1/messenger/conversations')->json('data'))->pluck('id')->all();
        $this->assertLessThan(array_search($a->id, $ids, true), array_search($b->id, $ids, true));

        $this->send($this->alice, $a, ['body' => 'سوم']);
        $ids = collect($this->as($this->admin)->getJson('/api/v1/messenger/conversations')->json('data'))->pluck('id')->all();
        $this->assertSame($a->id, $ids[0]);
    }

    /** API قدیم برای تب‌های باز کار می‌کند و پیامش در گفت‌وگوی درست می‌نشیند. */
    public function test_the_legacy_api_still_works_and_lands_in_conversations(): void
    {
        $this->as($this->alice)->postJson('/api/v1/messenger', ['body' => 'از نسخه‌ی قدیم'])->assertCreated();
        $this->assertSame($this->thread($this->unitA)->id, Message::withoutGlobalScopes()->latest('id')->first()->conversation_id);

        $this->as($this->admin)->postJson('/api/v1/messenger', [
            'body' => 'به دو واحد', 'audience' => 'units', 'unit_ids' => [$this->unitA->id, $this->unitB->id],
        ])->assertCreated();
        $group = Message::withoutGlobalScopes()->latest('id')->first()->conversation;
        $this->assertSame('group', $group->type);
        $this->assertFalse($group->members_can_post);

        $this->assertStringContainsString('به دو واحد', $this->body($this->as($this->alice)->getJson('/api/v1/messenger')->assertOk()));
    }

    /* ─────────────────────────── مهاجرتِ داده ─────────────────────────── */

    /** 🔴 هر پیامِ قبلی در گفت‌وگوی متناظرش؛ هیچ پیامی گم یا عوض نمی‌شود؛ تکرار بی‌اثر. */
    public function test_the_backfill_preserves_every_existing_message(): void
    {
        $legacy = fn (array $attrs) => DB::table('messages')->insertGetId([
            'complex_id' => $this->complex->id, 'body' => 'x', 'author_name' => 'x', 'unit_label' => 'x',
            'is_hidden' => false, 'created_at' => now(), 'updated_at' => now(), ...$attrs,
        ]);

        $all = $legacy(['audience' => 'all', 'user_id' => $this->admin->id]);
        $mgmtA = $legacy(['audience' => 'management', 'unit_id' => $this->unitA->id, 'user_id' => $this->alice->id]);
        $mgmtA2 = $legacy(['audience' => 'management', 'unit_id' => $this->unitA->id, 'user_id' => $this->admin->id]);
        $noUnit = $legacy(['audience' => 'management', 'unit_id' => null, 'user_id' => $this->bob->id]);
        $group1 = $legacy(['audience' => 'units', 'user_id' => $this->admin->id]);
        $group2 = $legacy(['audience' => 'units', 'user_id' => $this->admin->id]);
        foreach ([$group1, $group2] as $id) {
            DB::table('message_units')->insert([['message_id' => $id, 'unit_id' => $this->unitA->id], ['message_id' => $id, 'unit_id' => $this->unitB->id]]);
        }
        DB::table('message_reads')->insert(['message_id' => $mgmtA, 'user_id' => $this->admin->id, 'read_at' => now()]);

        $before = DB::table('messages')->orderBy('id')->get(['id', 'body', 'audience', 'unit_id', 'user_id'])->toArray();

        $this->assertSame(6, app(ConversationBackfill::class)->run());
        $this->assertSame(0, app(ConversationBackfill::class)->run(), 'اجرای دوباره چیزی را جابه‌جا کرد.');

        $conversation = fn (int $messageId) => Conversation::withoutGlobalScopes()->find(DB::table('messages')->where('id', $messageId)->value('conversation_id'));

        $this->assertSame('channel', $conversation($all)->type);
        $this->assertSame($conversation($mgmtA)->id, $conversation($mgmtA2)->id);
        $this->assertSame($this->unitA->id, $conversation($mgmtA)->unit_id);
        $this->assertSame(['user', $this->bob->id], [$conversation($noUnit)->type, $conversation($noUnit)->user_id]);
        $this->assertSame($conversation($group1)->id, $conversation($group2)->id, 'همان مجموعه‌ی واحدها، همان گروه.');
        $this->assertEqualsCanonicalizing([$this->unitA->id, $this->unitB->id], $conversation($group1)->units()->pluck('units.id')->all());
        $this->assertSame($mgmtA2, $conversation($mgmtA)->last_message_id);

        /*
         * 🔴 کدِ تازه همان گروهِ منتقل‌شده را پیدا می‌کند، نه دومی کنارش.
         *
         * خرابکاری نشان داد بی‌این ادعا کلیدِ انتقال می‌توانست با کلیدِ
         * `groupForUnits` فرق کند و هیچ تستی نمی‌فهمید: پیامِ بعدیِ مدیر به همان
         * واحدها در گروهِ تازه‌ای می‌نشست و تاریخچه دو تکه می‌شد.
         */
        $this->assertSame(
            $conversation($group1)->id,
            app(ConversationDirectory::class)->groupForUnits($this->complex, [$this->unitB->id, $this->unitA->id], $this->admin)->id,
        );

        // خودِ پیام‌ها و رسیدِ خواندن دست‌نخورده‌اند
        $this->assertEquals($before, DB::table('messages')->orderBy('id')->get(['id', 'body', 'audience', 'unit_id', 'user_id'])->toArray());
        $this->assertSame(1, DB::table('message_reads')->count());
    }

    /* ─────────────────────────── ۱۲+ سناریوی امنیتی ─────────────────────────── */

    /** ۱ — رشته‌ی خصوصیِ واحدِ دیگر: نه خواندن، نه نوشتن، نه رسید. همه ۴۰۴. */
    public function test_a_resident_cannot_reach_another_units_private_thread(): void
    {
        $theirs = $this->thread($this->unitB);
        $this->send($this->bob, $theirs, ['body' => 'خصوصیِ باب']);

        $this->as($this->alice)->getJson("/api/v1/messenger/conversations/{$theirs->id}/messages")->assertNotFound();
        $this->send($this->alice, $theirs, ['body' => 'نفوذ'])->assertNotFound();
        $this->as($this->alice)->postJson("/api/v1/messenger/conversations/{$theirs->id}/read", ['up_to' => 999999])->assertNotFound();

        $this->assertNotContains($theirs->id, collect($this->as($this->alice)->getJson('/api/v1/messenger/conversations')->json('data'))->pluck('id'));
        $this->assertSame(0, Message::withoutGlobalScopes()->where('body', 'نفوذ')->count());
    }

    /**
     * ۲ — ⚠️ قاعده در ۱۰/۹ عوض شد: کانال گروهِ اصلیِ مجتمع است و اعضا در آن می‌نویسند (بندهای ۳۲، ۵۴ و ۵۵)؛ فقط اگر
     * مدیر «فقط مدیریت بنویسد» را بگذارد بسته است — و آن را فقط مدیر می‌گذارد.
     */
    public function test_residents_post_to_the_community_group_unless_management_closes_it(): void
    {
        $this->as($this->alice)->getJson('/api/v1/messenger/conversations');
        $channel = Conversation::withoutGlobalScopes()->where('key', 'channel')->firstOrFail();

        $this->send($this->alice, $channel, ['body' => 'سلام همسایه‌ها'])->assertCreated();

        $this->as($this->alice)->postJson("/api/v1/messenger/conversations/{$channel->id}/posting", ['members_can_post' => false])->assertForbidden();
        $this->as($this->admin)->postJson("/api/v1/messenger/conversations/{$channel->id}/posting", ['members_can_post' => false])->assertOk();

        $this->send($this->alice, $channel, ['body' => 'به همه'])
            ->assertForbidden()->assertJsonPath('code', 'messenger.read_only');
        $this->send($this->admin, $channel, ['body' => 'اطلاعیه'])->assertCreated();
    }

    /** ۳ — 🔴 مستأجرِ رفته دیگر رشته‌ی (بایگانیِ) واحدِ قبلی‌اش را نمی‌بیند. */
    public function test_a_former_tenant_loses_the_old_units_thread(): void
    {
        $thread = $this->archive($this->unitA, $this->admin, 'پاسخِ مدیریت به ساکنِ فعلی');

        $former = $this->person('مستأجرِ قبلی', '09120000074', UserRole::Tenant);
        $former->units()->attach($this->unitA->id, [
            'complex_id' => $this->complex->id, 'relation' => 'tenant', 'share_percent' => 100, 'is_current' => false,
        ]);

        $this->as($former)->getJson("/api/v1/messenger/conversations/{$thread->id}/messages")->assertNotFound();
        // API قدیم هم همان قاعده را دارد
        $this->assertStringNotContainsString('پاسخِ مدیریت به ساکنِ فعلی', $this->body($this->as($former)->getJson('/api/v1/messenger')->assertOk()));
        // ضدِ پوچی: ساکنِ فعلی همان را می‌بیند
        $this->assertStringContainsString('پاسخِ مدیریت به ساکنِ فعلی', $this->body($this->as($this->alice)->getJson('/api/v1/messenger')));
    }

    /** ۴ — پیوستِ رشته‌ی دیگری با شناسه‌ی پیام هم خوانده نمی‌شود. */
    public function test_an_attachment_in_another_thread_stays_private(): void
    {
        Storage::fake(PrivateFiles::name());
        $theirs = $this->thread($this->unitB);
        $id = $this->send($this->bob, $theirs, ['attachment' => UploadedFile::fake()->image('x.jpg')])->assertCreated()->json('message.id');

        $this->as($this->alice)->get("/api/v1/messenger/{$id}/attachment")->assertNotFound();
        $this->as($this->bob)->get("/api/v1/messenger/{$id}/attachment")->assertOk();
    }

    /** ۵ — رسیدِ خواندن فقط پیام‌های همان گفت‌وگو را علامت می‌زند. */
    public function test_read_receipts_never_leak_across_conversations(): void
    {
        $mine = $this->thread($this->unitA);
        $theirs = $this->thread($this->unitB);
        $this->send($this->admin, $mine, ['body' => 'برای آلیس']);
        $other = $this->send($this->admin, $theirs, ['body' => 'برای باب'])->json('message.id');

        $this->as($this->alice)->postJson("/api/v1/messenger/conversations/{$mine->id}/read", ['up_to' => $other + 100])->assertOk();

        $this->assertDatabaseMissing('message_reads', ['message_id' => $other, 'user_id' => $this->alice->id]);
    }

    /** ۶ — ارسالِ دوباره با همان client_id پیامِ دوم نمی‌سازد. */
    public function test_a_retried_send_does_not_duplicate(): void
    {
        $thread = $this->thread($this->unitA);

        $first = $this->send($this->alice, $thread, ['body' => 'یک بار', 'client_id' => 'retry-1'])->assertCreated();
        $again = $this->send($this->alice, $thread, ['body' => 'یک بار', 'client_id' => 'retry-1'])->assertOk();

        $this->assertSame($first->json('message.id'), $again->json('message.id'));
        $this->assertSame(1, Message::withoutGlobalScopes()->where('client_id', 'retry-1')->count());
    }

    /**
     * ۷ — client_idِ مصرف‌شده در گفت‌وگوی دیگر ۴۰۹ است، نه برگرداندنِ آن پیام.
     *
     * ⚠️ نسخه‌ی اولِ این تست شناسه را در گفت‌وگویی می‌فرستاد که آلیس نمی‌بیند —
     * ۴۰۴ِ دید پیش از رسیدن به قاعده‌ی شناسه می‌آمد و تست چیزی نمی‌سنجید.
     * حالا در گروهی که آلیس واقعاً در آن می‌نویسد.
     */
    public function test_a_reused_client_id_elsewhere_reveals_nothing(): void
    {
        $this->send($this->alice, $this->thread($this->unitA), ['body' => 'محرمانه', 'client_id' => 'same'])->assertCreated();

        $group = $this->as($this->admin)->postJson('/api/v1/messenger/conversations', [
            'type' => 'group', 'title' => 'گروهِ باز', 'unit_ids' => [$this->unitA->id], 'members_can_post' => true,
        ])->json('conversation.id');

        $conflict = $this->as($this->alice)->postJson("/api/v1/messenger/conversations/{$group}/messages", ['body' => 'x', 'client_id' => 'same']);

        $conflict->assertStatus(409)->assertJsonPath('code', 'messenger.client_id_reused');
        $this->assertStringNotContainsString('محرمانه', $this->body($conflict));
        $this->assertSame(0, Message::withoutGlobalScopes()->where('conversation_id', $group)->count());
    }

    /** ۸ — متنِ پیامِ پنهان‌شده به ساکن نمی‌رسد: نه در تاریخچه، نه در پیش‌نمایشِ فهرست. */
    public function test_a_hidden_message_body_never_reaches_residents(): void
    {
        $thread = $this->thread($this->unitA);
        $id = $this->send($this->admin, $thread, ['body' => 'متنِ پنهان'])->json('message.id');
        Message::withoutGlobalScopes()->whereKey($id)->update(['is_hidden' => true]);

        $resident = $this->as($this->alice)->getJson("/api/v1/messenger/conversations/{$thread->id}/messages")
            ->assertOk()->assertJsonPath('hiddenIds', [$id]);
        $this->assertStringNotContainsString('متنِ پنهان', $this->body($resident));
        $this->assertStringNotContainsString('متنِ پنهان', $this->body($this->as($this->alice)->getJson('/api/v1/messenger/conversations')));
        // ضدِ پوچی: مدیر متن را می‌بیند
        $this->assertStringContainsString('متنِ پنهان', $this->body($this->as($this->admin)->getJson("/api/v1/messenger/conversations/{$thread->id}/messages")));
    }

    /** ۹ — گروه: بیرونی نمی‌بیند؛ عضو فقط اگر گروه اجازه دهد می‌نویسد. */
    public function test_group_membership_and_posting_rights_are_enforced(): void
    {
        $outsider = $this->person('بیرونی', '09120000075', UserRole::Owner);
        $this->unit('103', $outsider);

        $readOnly = $this->as($this->admin)->postJson('/api/v1/messenger/conversations', [
            'type' => 'group', 'title' => 'طبقه‌ی اول', 'unit_ids' => [$this->unitA->id, $this->unitB->id], 'members_can_post' => false,
        ])->assertCreated()->json('conversation.id');

        $open = $this->as($this->admin)->postJson('/api/v1/messenger/conversations', [
            'type' => 'group', 'title' => 'هیئت مدیره', 'unit_ids' => [$this->unitA->id], 'members_can_post' => true,
        ])->json('conversation.id');

        $this->as($outsider)->getJson("/api/v1/messenger/conversations/{$readOnly}/messages")->assertNotFound();
        $this->as($this->alice)->getJson("/api/v1/messenger/conversations/{$readOnly}/messages")->assertOk();
        $this->as($this->alice)->postJson("/api/v1/messenger/conversations/{$readOnly}/messages", ['body' => 'x'])->assertForbidden();
        $this->as($this->alice)->postJson("/api/v1/messenger/conversations/{$open}/messages", ['body' => 'سلام'])->assertCreated();
        $this->as($this->bob)->postJson("/api/v1/messenger/conversations/{$open}/messages", ['body' => 'x'])->assertNotFound();
    }

    /** ۱۰ — فیلدهای مخفی در بدنه نادیده گرفته می‌شوند: گفت‌وگو از مسیر است و نویسنده از نشست. */
    public function test_forged_fields_in_the_payload_are_ignored(): void
    {
        $mine = $this->thread($this->unitA);
        $theirs = $this->thread($this->unitB);

        $id = $this->send($this->alice, $mine, [
            'body' => 'جعل', 'conversation_id' => $theirs->id, 'user_id' => $this->bob->id,
            'complex_id' => 999, 'audience' => 'all', 'is_hidden' => true,
        ])->assertCreated()->json('message.id');

        $message = Message::withoutGlobalScopes()->findOrFail($id);
        $this->assertSame([$mine->id, $this->alice->id, $this->complex->id, 'management', false],
            [$message->conversation_id, $message->user_id, $message->complex_id, $message->audience->value, $message->is_hidden]);
    }

    /** ۱۱ — گفت‌وگو را فقط مدیریت می‌سازد، و فقط با واحدهای مجتمعِ خودش. */
    public function test_only_management_creates_conversations_within_its_complex(): void
    {
        $this->as($this->alice)->postJson('/api/v1/messenger/conversations', ['type' => 'unit', 'unit_id' => $this->unitB->id])->assertForbidden();

        $foreign = Complex::create(['type' => 'residential', 'name' => 'دیگر', 'slug' => 'other-'.uniqid(),
            'currency' => 'toman', 'charge_due_day' => 10, 'payment_gateway' => 'none']);
        $foreignUnit = Unit::withoutGlobalScopes()->create([
            'complex_id' => $foreign->id, 'unit_number' => '9', 'floor' => 1, 'area' => 50,
            'residents_count' => 1, 'coefficient' => 1, 'occupancy_status' => OccupancyStatus::OwnerOccupied,
        ]);

        $this->as($this->admin)->postJson('/api/v1/messenger/conversations', ['type' => 'unit', 'unit_id' => $foreignUnit->id])->assertUnprocessable();
        $this->as($this->admin)->postJson('/api/v1/messenger/conversations', ['type' => 'group', 'title' => 'x', 'unit_ids' => [$foreignUnit->id]])->assertUnprocessable();
    }

    /**
     * ۱۲ — مجتمعی که پیام‌رسانش بسته است حتی مدیرش نمی‌نویسد، و کاربرِ
     * محدودشده هم نه.
     *
     * ⚠️ دو آزمونِ جدا: لاراول نمونه‌ی کنترلر را روی مسیر نگه می‌دارد و مجتمعِ
     * جاری را در آن؛ در یک آزمون، درخواستِ دوم مجتمعِ درخواستِ اول را می‌دید.
     */
    public function test_a_closed_messenger_refuses_even_management(): void
    {
        $this->complex->forceFill(['messenger_enabled' => false])->save();

        $this->send($this->admin, $this->thread($this->unitA), ['body' => 'x'])->assertForbidden();
    }

    /** ⚠️ ۱۰/۹: بستن روی عضویتِ همین مجتمع است، نه `users.can_message` (`MembershipMessagingTest`). */
    public function test_a_muted_resident_cannot_send(): void
    {
        MessagingAccess::toggle($this->alice, $this->complex->id);

        $this->send($this->alice->fresh(), $this->thread($this->unitA), ['body' => 'x'])
            ->assertForbidden()->assertJsonPath('message', 'امکان ارسال پیام برای شما فعال نیست.');
        $this->assertSame(0, Message::withoutGlobalScopes()->count());
    }

    /**
     * ۱۵ — «خوانده شد» یعنی **کسِ دیگری** خوانده، نه خودِ نویسنده.
     *
     * 🔴 خرابکاری پیدایش کرد: تبِ قدیمی با `POST /messenger/read` پیامِ خودِ
     * نویسنده را هم علامت می‌زند (API قدیم تا گامِ Contract می‌ماند). بی‌این
     * تست، شمردنِ همان رسید پیام را برای نویسنده «خوانده شد» نشان می‌داد.
     */
    public function test_reading_your_own_message_does_not_mark_it_read(): void
    {
        $thread = $this->thread($this->unitA);
        $id = $this->send($this->alice, $thread, ['body' => 'سلام'])->assertCreated()->json('message.id');

        $this->as($this->alice)->postJson('/api/v1/messenger/read', ['ids' => [$id]])->assertOk();
        $this->assertSame(1, DB::table('message_reads')->where('message_id', $id)->where('user_id', $this->alice->id)->count());

        $view = $this->as($this->alice)->getJson("/api/v1/messenger/conversations/{$thread->id}/messages")->assertOk();

        $this->assertSame(0, $view->json('readUpTo'));
        $this->assertSame('sent', collect($view->json('messages'))->firstWhere('id', $id)['status']);
    }

    /**
     * ۱۴ — پیامی که کدِ قدیمی در فاصله‌ی استقرار نوشته (هنوز بی‌گفت‌وگو) هم
     * همان قاعده‌ی دید را دارد — و مستأجرِ رفته آن را هم نمی‌بیند.
     */
    public function test_a_message_still_awaiting_backfill_keeps_the_same_privacy(): void
    {
        DB::table('messages')->insert([
            'complex_id' => $this->complex->id, 'user_id' => $this->bob->id, 'body' => 'در فاصله‌ی استقرار',
            'audience' => 'management', 'unit_id' => $this->unitB->id, 'author_name' => 'باب', 'unit_label' => '102',
            'is_hidden' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $former = $this->person('ساکنِ پیشینِ ۱۰۲', '09120000077', UserRole::Tenant);
        $former->units()->attach($this->unitB->id, [
            'complex_id' => $this->complex->id, 'relation' => 'tenant', 'share_percent' => 100, 'is_current' => false,
        ]);

        $sees = fn (User $user) => str_contains($this->body($this->as($user)->getJson('/api/v1/messenger')), 'در فاصله‌ی استقرار');

        $this->assertTrue($sees($this->bob));
        $this->assertTrue($sees($this->admin));
        $this->assertFalse($sees($this->alice));
        $this->assertFalse($sees($former), '🔴 مستأجرِ رفته پیامِ بی‌گفت‌وگوی واحدِ قبلی را دید.');
    }

    /** ۱۳ — ساکنِ بی‌واحد هم رشته‌ای با مدیریت دارد، و فقط خودش (و مدیریت) می‌بیندش. */
    public function test_a_resident_without_a_unit_has_a_private_line_to_management(): void
    {
        $newcomer = $this->person('تازه‌وارد', '09120000076', UserRole::Tenant);

        $row = collect($this->as($newcomer)->getJson('/api/v1/messenger/conversations')->json('data'))->firstWhere('type', 'user');
        $this->assertNotNull($row);
        $this->as($newcomer)->postJson("/api/v1/messenger/conversations/{$row['id']}/messages", ['body' => 'سلام'])->assertCreated();
        $this->as($this->admin)->postJson("/api/v1/messenger/conversations/{$row['id']}/messages", ['body' => 'خوش آمدید'])->assertCreated();

        $this->assertStringContainsString('خوش آمدید', $this->body($this->as($newcomer)->getJson("/api/v1/messenger/conversations/{$row['id']}/messages")));
        $this->as($this->alice)->getJson("/api/v1/messenger/conversations/{$row['id']}/messages")->assertNotFound();
    }
}
