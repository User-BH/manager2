<?php

namespace Tests\Feature;

use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\Membership;
use App\Services\Messenger\ConversationDirectory;
use App\Services\Messenger\MessagingAccess;
use App\Services\Units\TenureService;
use App\Support\PrivateFiles;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ساختارِ پیام‌رسان — مرحلهٔ ۱۰ · فاز ۹ (بندهای ۳۱ تا ۴۰، ۷۲ و ۱۱۷ ِ فایلِ معماری).
 *
 * هر عضو از همان بارِ اول: گروهِ مجتمع، گفت‌وگوی خصوصیِ **خودش** با مدیریت، «پیام‌های ذخیره‌شده»، و — اگر واحدش
 * هم مالک دارد هم مستأجر — «مالک ↔ مستأجر». مدیر «مالک ↔ مستأجر» و «ذخیره‌شده»ِ دیگران را هرگز نمی‌بیند؛ نه در
 * فهرست، نه با شناسه، نه در جست‌وجو و پیوست. سناریوی بندِ ۱۱۷ یک‌جا: مالک می‌آید، مستأجر می‌آید، مستأجر می‌رود،
 * مستأجرِ تازه می‌آید.
 */
class MessengerStructureTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $manager;

    private Unit $unit;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create(['name' => 'مجتمع مسکونی آفتاب', 'messenger_enabled' => true]);
        $this->manager = $this->person('مدیر آفتاب', '09129990001', UserRole::ComplexAdmin);
        $this->unit = Unit::factory()->create(['complex_id' => $this->complex->id, 'unit_number' => '10']);
        $this->owner = $this->person('مالک ده', '09129990002', UserRole::Owner);
        app(TenureService::class)->open($this->unit, $this->owner, ResidentRelation::Owner, 100, now()->subYear());
    }

    /* ── بندهای ۳۱، ۳۲، ۳۳، ۳۹ و ۴۰ ───────────────────────────────────── */

    public function test_an_owner_alone_gets_group_manager_and_saved_and_no_fake_tenant_chat(): void
    {
        $list = $this->list($this->owner);

        $this->assertEqualsCanonicalizing(['channel', 'user', 'saved'], array_column($list, 'type'));
        $titles = array_column($list, 'title', 'type');
        $this->assertSame('گروهِ مجتمع مسکونی آفتاب', $titles['channel']);
        $this->assertSame('مدیریتِ ساختمان', $titles['user']);
        $this->assertSame('پیام‌های ذخیره‌شده', $titles['saved']);
        $this->assertSame(0, Conversation::withoutGlobalScopes()->where('type', Conversation::PAIR)->count(), 'گفت‌وگوی جعلی با مستأجری که نیست.');
    }

    public function test_the_group_name_follows_the_community_name(): void
    {
        $this->list($this->owner);
        $this->complex->forceFill(['name' => 'مجتمع تجاری پردیس'])->save();

        $this->assertSame('گروهِ مجتمع تجاری پردیس', collect($this->list($this->owner))->firstWhere('type', 'channel')['title']);
        $this->assertSame(1, Conversation::withoutGlobalScopes()->where('type', Conversation::CHANNEL)->count());
    }

    /** بندهای ۳۷، ۳۸ و ۴۰: مالک و مستأجر هر کدام گفت‌وگوی خودشان با مدیریت، و یکی با هم. */
    public function test_owner_and_tenant_each_get_their_own_manager_chat_and_one_together(): void
    {
        $tenant = $this->tenant('مستأجر ده', '09129990003');

        $ownerList = collect($this->list($this->owner));
        $tenantList = collect($this->list($tenant));
        $this->assertEqualsCanonicalizing(['channel', 'user', 'saved', 'pair'], $ownerList->pluck('type')->all());
        $this->assertEqualsCanonicalizing(['channel', 'user', 'saved', 'pair'], $tenantList->pluck('type')->all());

        $this->assertSame('مستأجر ده · مستأجر', $ownerList->firstWhere('type', 'pair')['title']);
        $this->assertSame('مالک ده · مالک', $tenantList->firstWhere('type', 'pair')['title']);
        $this->assertSame($ownerList->firstWhere('type', 'pair')['id'], $tenantList->firstWhere('type', 'pair')['id']);

        // گفت‌وگوی مالک با مدیر را مستأجر نمی‌خواند — پیش از ۱۰/۹ رشته‌ی واحد مشترک بود
        $ownersManagerChat = $ownerList->firstWhere('type', 'user')['id'];
        $this->send($this->owner, $ownersManagerChat, 'فقط برای مدیر')->assertCreated();
        $this->read($tenant, $ownersManagerChat)->assertNotFound();
        $this->assertNotSame($ownersManagerChat, $tenantList->firstWhere('type', 'user')['id']);

        // هر دو در گفت‌وگوی مشترک می‌نویسند
        $pair = $ownerList->firstWhere('type', 'pair')['id'];
        $this->send($this->owner, $pair, 'کلیدِ انباری کجاست؟')->assertCreated();
        $this->send($tenant, $pair, 'زیرِ پادری')->assertCreated();
        $this->assertSame(['کلیدِ انباری کجاست؟', 'زیرِ پادری'], collect($this->read($tenant, $pair)->json('messages'))->pluck('body')->all());
    }

    /** 🔴 بندهای ۳۸ و ۳۹: مدیر «مالک ↔ مستأجر» و «ذخیره‌شده»ِ دیگران را از هیچ راهی نمی‌بیند. */
    public function test_the_manager_never_reaches_a_pair_or_someone_elses_saved_messages(): void
    {
        Storage::fake(PrivateFiles::name());
        $tenant = $this->tenant('مستأجر ده', '09129990003');
        $list = collect($this->list($this->owner));
        $pair = $list->firstWhere('type', 'pair')['id'];
        $saved = $list->firstWhere('type', 'saved')['id'];

        $inPair = $this->send($this->owner, $pair, 'رمزِ وای‌فای: ستاره')->json('message.id');
        $note = $this->as($this->owner)->post("/api/v1/messenger/conversations/{$saved}/messages", [
            'body' => 'یادداشتِ شخصی', 'attachment' => UploadedFile::fake()->image('note.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('message.id');

        $managerList = collect($this->list($this->manager));
        $this->assertNotContains($pair, $managerList->pluck('id'));
        $this->assertNotContains($saved, $managerList->pluck('id'));
        $this->assertSame(1, $managerList->where('type', 'saved')->count(), 'مدیر «ذخیره‌شده»ِ خودش را دارد.');

        $this->read($this->manager, $pair)->assertNotFound();
        $this->read($this->manager, $saved)->assertNotFound();
        $this->send($this->manager, $pair, 'نفوذ')->assertNotFound();
        $this->as($this->manager)->get("/api/v1/messenger/{$note}/attachment")->assertNotFound();
        $this->as($this->manager)->patchJson("/api/v1/messenger/{$inPair}/toggle-hide")->assertNotFound();
        $this->assertFalse((bool) Message::withoutGlobalScopes()->whereKey($inPair)->value('is_hidden'));

        foreach (['ستاره', 'یادداشتِ شخصی'] as $term) {
            $this->assertSame(0, $this->as($this->manager)->getJson('/api/v1/search?q='.urlencode($term))->json('total'), $term);
        }
        // API قدیم هم
        $legacy = json_encode($this->as($this->manager)->getJson('/api/v1/messenger')->assertOk()->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('ستاره', (string) $legacy);
        $this->assertStringNotContainsString('یادداشتِ شخصی', (string) $legacy);

        // سیاستِ پنهان‌کردن خودش هم پیامِ نادیده را رد می‌کند — نه فقط ۴۰۴ ِ کنترلر (خرابکاریِ ۱۰/۹)
        app(TenantContext::class)->set(null);
        $this->actingAs($this->manager);
        $this->assertFalse(Gate::forUser($this->manager)->allows('hide', Message::withoutGlobalScopes()->findOrFail($inPair)));
        $this->assertFalse(Gate::forUser($this->manager)->allows('closePoll', Message::withoutGlobalScopes()->findOrFail($note)));

        // «ذخیره‌شده» مالِ یک نفر است، حتی از دیدِ هم‌واحدی‌اش
        $this->read($tenant, $saved)->assertNotFound();
        $this->as($tenant)->get("/api/v1/messenger/{$note}/attachment")->assertNotFound();
        $this->as($this->owner)->get("/api/v1/messenger/{$note}/attachment")->assertOk();
    }

    /** «ذخیره‌شده» یادداشتِ خودِ اوست: بستنِ پیام‌رسانِ عضو آن را نمی‌بندد؛ بستنِ کلِ پیام‌رسانِ مجتمع می‌بندد. */
    public function test_saved_messages_stay_writable_for_a_muted_member(): void
    {
        $list = collect($this->list($this->owner));
        MessagingAccess::toggle($this->owner, $this->complex->id);

        $this->send($this->owner, $list->firstWhere('type', 'saved')['id'], 'یادداشت')->assertCreated();
        $this->send($this->owner, $list->firstWhere('type', 'user')['id'], 'به مدیر')->assertForbidden();
    }

    /* ── بندهای ۷۲ و ۱۱۷: چرخه‌ی کامل ──────────────────────────────────── */

    public function test_the_lifecycle_of_the_spec_from_owner_to_a_new_tenant(): void
    {
        // مالک می‌آید ← گروه، مدیریت، ذخیره‌شده
        $this->assertEqualsCanonicalizing(['channel', 'user', 'saved'], array_column($this->list($this->owner), 'type'));

        // مستأجر می‌آید ← مالک گفت‌وگوی مستأجر را می‌گیرد
        $old = $this->tenant('مستأجرِ قدیم', '09129990003');
        $oldPair = collect($this->list($this->owner))->firstWhere('type', 'pair')['id'];
        $oldManagerChat = collect($this->list($old))->firstWhere('type', 'user')['id'];
        $this->send($old, $oldPair, 'آبگرمکن صدا می‌دهد')->assertCreated();
        $this->send($old, $oldManagerChat, 'قبضِ آب را کجا بدهم؟')->assertCreated();

        // مستأجر می‌رود ← دسترسیِ او بسته، تاریخچه سرِ جایش
        app(Membership::class)->end($old, $this->manager, $this->complex->id);
        $this->read($old->fresh(), $oldPair)->assertNotFound();
        $this->read($old->fresh(), $oldManagerChat)->assertNotFound();
        $this->assertSame(2, Message::withoutGlobalScopes()->whereIn('conversation_id', [$oldPair, $oldManagerChat])->count());

        // مالک تاریخچه را فقط‌خواندنی می‌بیند (تصمیمِ کارفرما، ۱۰/۹)
        $ownerView = $this->read($this->owner, $oldPair)->assertOk();
        $this->assertSame(['آبگرمکن صدا می‌دهد'], collect($ownerView->json('messages'))->pluck('body')->all());
        $this->assertFalse($ownerView->json('conversation.canPost'));
        $this->assertNotNull($ownerView->json('conversation.readOnlyReason'));
        $this->send($this->owner, $oldPair, 'هنوز آن‌جایی؟')->assertForbidden()->assertJsonPath('code', 'messenger.read_only');

        // مستأجرِ تازه می‌آید ← گفت‌وگوهای خصوصیِ تازه؛ تاریخچه‌ی قبلی جدا
        $new = $this->tenant('مستأجرِ تازه', '09129990004');
        $newList = collect($this->list($new));
        $newPair = $newList->firstWhere('type', 'pair')['id'];
        $this->assertNotSame($oldPair, $newPair);
        $this->assertNotSame($oldManagerChat, $newList->firstWhere('type', 'user')['id']);
        $this->read($new, $oldPair)->assertNotFound();
        $this->read($new, $oldManagerChat)->assertNotFound();
        $this->assertSame([], $this->read($new, $newPair)->json('messages'));

        // مالک حالا هر دو را دارد: قدیمی فقط‌خواندنی، تازه نوشتنی
        $ownerPairs = collect($this->list($this->owner))->where('type', 'pair')->keyBy('id');
        $this->assertFalse($ownerPairs[$oldPair]['canPost']);
        $this->assertTrue($ownerPairs[$newPair]['canPost']);

        // و مدیر گفت‌وگوی خودش با مستأجرِ رفته را هنوز دارد (تاریخچه‌ی مدیریت)
        $this->assertSame(['قبضِ آب را کجا بدهم؟'], collect($this->read($this->manager, $oldManagerChat)->json('messages'))->pluck('body')->all());
    }

    /* ── بایگانیِ رشته‌ی مشترکِ واحد (تصمیمِ کارفرما) ──────────────────── */

    public function test_the_old_shared_unit_thread_is_kept_read_only_for_whoever_saw_it(): void
    {
        $tenant = $this->tenant('مستأجر ده', '09129990003');
        app(TenantContext::class)->set(null);
        $archive = app(ConversationDirectory::class)->unitThread($this->complex, $this->unit);
        $id = DB::table('messages')->insertGetId([
            'complex_id' => $this->complex->id, 'conversation_id' => $archive->id, 'user_id' => $this->owner->id,
            'body' => 'پیامِ پیش از ۱۰/۹', 'audience' => 'management', 'unit_id' => $this->unit->id,
            'author_name' => 'مالک ده', 'is_hidden' => false, 'created_at' => now()->subMonth(), 'updated_at' => now()->subMonth(),
        ]);
        $archive->forceFill(['last_message_id' => $id])->save();

        $this->assertSame('بایگانیِ گفت‌وگوی واحد با مدیریت', collect($this->list($this->owner))->firstWhere('type', 'unit')['title']);
        $this->assertSame(['پیامِ پیش از ۱۰/۹'], collect($this->read($this->owner, $archive->id)->json('messages'))->pluck('body')->all());

        foreach ([$this->owner, $this->manager] as $writer) {
            $this->send($writer, $archive->id, 'تازه')->assertForbidden()->assertJsonPath('code', 'messenger.read_only');
        }
        $this->assertSame(1, Message::withoutGlobalScopes()->where('conversation_id', $archive->id)->count());
        // مستأجری که پیش از آن پیام ساکن نبود همان قاعده‌ی دوره را دارد (مرحلهٔ ۹ · فاز ۶)
        $this->assertSame([], $this->read($tenant, $archive->id)->json('messages'));
    }

    /** مدیر گفت‌وگوی خصوصی را با **یک عضو** باز می‌کند، نه با واحد؛ عضوِ مجتمعِ دیگر یا رفته نه. */
    public function test_the_manager_opens_a_private_chat_with_one_member_only(): void
    {
        $tenant = $this->tenant('مستأجر ده', '09129990003');
        $other = Complex::factory()->create();
        $stranger = User::factory()->create(['complex_id' => $other->id, 'role' => UserRole::Owner, 'is_active' => true]);

        $chat = $this->as($this->manager)->postJson('/api/v1/messenger/conversations', ['type' => 'user', 'user_id' => $tenant->id])
            ->assertCreated()->json('conversation');
        $this->assertSame('مستأجر ده', $chat['title']);
        $this->assertSame($chat['id'], collect($this->list($tenant))->firstWhere('type', 'user')['id'], 'همان گفت‌وگوی عضو، نه دومی.');

        $this->as($this->manager)->postJson('/api/v1/messenger/conversations', ['type' => 'user', 'user_id' => $stranger->id])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->as($this->manager)->postJson('/api/v1/messenger/conversations', ['type' => 'unit', 'unit_id' => $this->unit->id])
            ->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->as($this->owner)->postJson('/api/v1/messenger/conversations', ['type' => 'user', 'user_id' => $tenant->id])
            ->assertForbidden();

        $members = collect($this->as($this->manager)->getJson('/api/v1/messenger/conversations')->json('members'));
        $this->assertSame(['مالک ده · مالک واحد 10', 'مستأجر ده · مستاجر واحد 10'], $members->pluck('label')->sort()->values()->all());
        $this->assertSame([], $this->as($this->owner)->getJson('/api/v1/messenger/conversations')->json('members'));
    }

    /* ── کمک‌ها ─────────────────────────────────────────────────────────── */

    private function person(string $name, string $phone, UserRole $role): User
    {
        return User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => $role, 'is_active' => true, 'name' => $name, 'phone' => $phone, 'can_message' => true,
        ]);
    }

    private function tenant(string $name, string $phone): User
    {
        $tenant = $this->person($name, $phone, UserRole::Tenant);
        app(TenureService::class)->open($this->unit, $tenant, ResidentRelation::Tenant, 100, now()->subDay());

        return $tenant;
    }

    /** @return list<array<string, mixed>> */
    private function list(User $user): array
    {
        return $this->as($user)->getJson('/api/v1/messenger/conversations')->assertOk()->json('data');
    }

    private function read(User $user, int $conversation): TestResponse
    {
        return $this->as($user)->getJson("/api/v1/messenger/conversations/{$conversation}/messages");
    }

    private function send(User $user, int $conversation, string $body): TestResponse
    {
        return $this->as($user)->postJson("/api/v1/messenger/conversations/{$conversation}/messages", ['body' => $body]);
    }

    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();

        return $this->actingAs($user);
    }
}
