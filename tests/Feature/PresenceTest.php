<?php

namespace Tests\Feature;

use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\ComplexMembership;
use App\Models\Conversation;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\MembershipLedger;
use App\Services\Messenger\ConversationDirectory;
use App\Services\Messenger\Presence;
use App\Services\Units\TenureService;
use App\Support\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * حضورِ آنلاین در پیام‌رسان — «چند نکته»، بندِ ۳.
 *
 * تعریف (`Presence`): «آنلاین» یعنی درخواستِ واردشده از داشبوردِ باز در ۹۰ ثانیه‌ی اخیر. ضربان همان pollingهای
 * موجود است؛ خروج و بستنِ صفحه همان لحظه آفلاین می‌کنند و بقیه (قطعِ شبکه، خوابِ دستگاه، پایانِ نشست) با گذشتنِ
 * پنجره. حضور فقط به هم‌گفت‌وگو می‌رسد و فقط برای عضوِ فعالِ همان مجتمع.
 */
class PresenceTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $manager;

    private Unit $unit;

    private User $owner;

    private User $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create(['messenger_enabled' => true]);
        $this->manager = $this->person('مدیر', '09129990101', UserRole::ComplexAdmin);
        $this->unit = Unit::factory()->create(['complex_id' => $this->complex->id, 'unit_number' => '10']);
        $this->owner = $this->person('مالک ده', '09129990102', UserRole::Owner);
        $this->tenant = $this->person('مستأجر ده', '09129990103', UserRole::Tenant);
        app(TenureService::class)->open($this->unit, $this->owner, ResidentRelation::Owner, 100, now()->subYear());
        app(TenureService::class)->open($this->unit, $this->tenant, ResidentRelation::Tenant, 100, now()->subDay());
    }

    /** 🔴 تعریف: درخواست در پنجره یعنی آنلاین؛ ۹۰ ثانیه بی‌درخواست یعنی آفلاین — بی آن‌که کسی چیزی بنویسد. */
    public function test_online_means_a_request_inside_the_window_and_expires_on_its_own(): void
    {
        $channel = $this->channel();
        $this->as($this->owner)->getJson('/api/v1/messenger/conversations')->assertOk();

        $info = $this->info($this->manager, $channel);
        $owner = collect($info->json('members'))->firstWhere('id', $this->owner->id);
        $this->assertNotNull($owner['onlineUntil']);
        $this->assertEqualsWithDelta($info->json('serverTime') + Presence::WINDOW, $owner['onlineUntil'], 2);
        // مالک و خودِ مدیر (همین درخواست)؛ مستأجر هیچ درخواستی نزده
        $this->assertSame(2, $info->json('counts.online'));
        $this->assertNull(collect($info->json('members'))->firstWhere('id', $this->tenant->id)['onlineUntil']);

        // پایانِ نشست، قطعِ شبکه، خوابِ دستگاه: هیچ درخواستی نمی‌آید و پنجره می‌گذرد
        $this->travel(Presence::WINDOW + 1)->seconds();
        $info = $this->info($this->manager, $channel);
        $this->assertNull(collect($info->json('members'))->firstWhere('id', $this->owner->id)['onlineUntil']);
        $this->assertSame(1, $info->json('counts.online'), 'فقط خودِ مدیر که همین حالا پرسید.');
    }

    /** ضربان بارِ نوشتنِ تازه نمی‌سازد: در هر ۳۰ ثانیه حداکثر یک UPDATE برای هر حساب، و هیچ درخواستِ تازه‌ای. */
    public function test_the_heartbeat_writes_at_most_once_per_throttle_window(): void
    {
        $writes = 0;
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^update\s+"?users"?\s+set\s+"?last_seen_at"?/i', $query->sql)) {
                $writes++;
            }
        });

        // هر درخواست حسابِ تازه از پایگاه‌داده، مثلِ نشستِ واقعی
        $request = fn () => $this->as($this->owner->fresh())->getJson('/api/v1/notifications')->assertOk();

        $request();
        $request();
        $this->travel(Presence::THROTTLE - 5)->seconds();
        $request();
        $this->assertSame(1, $writes, 'درونِ پنجره‌ی ۳۰ ثانیه فقط یک نوشتن.');

        $this->travel(10)->seconds();
        $request();
        $this->assertSame(2, $writes);
    }

    /** 🔴 خروج و بستنِ صفحه همان لحظه آفلاین می‌کنند — نه ۹۰ ثانیه بعد. */
    public function test_logout_and_page_close_end_presence_at_once(): void
    {
        $this->as($this->owner)->getJson('/api/v1/notifications')->assertOk();
        $this->assertNotNull($this->owner->fresh()->last_seen_at);

        // `pagehide` ← keepalive
        $this->as($this->owner->fresh())->postJson('/api/v1/presence/offline')->assertOk()->assertExactJson(['online' => false]);
        $this->assertNull($this->owner->fresh()->last_seen_at);
        $this->assertNull(Presence::until($this->owner->fresh()->last_seen_at));

        // تبِ دیگرِ هنوز باز با پرسشِ بعدی‌اش برمی‌گرداند
        $this->as($this->owner->fresh())->getJson('/api/v1/notifications')->assertOk();
        $this->assertNotNull($this->owner->fresh()->last_seen_at);

        $this->as($this->owner->fresh())->postJson('/api/v1/logout')->assertOk();
        $this->assertNull($this->owner->fresh()->last_seen_at);

        // بی‌ورود: نه ضربان، نه «آفلاین شدم»
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->postJson('/api/v1/presence/offline')->assertUnauthorized();
    }

    /** 🔴 شمارِ آنلاینِ گروه همان اعضای فعال است: معلق، رفته و مجتمعِ دیگر — هر چه هم تازه دیده شده باشند — نه. */
    public function test_the_group_count_holds_only_active_members_of_that_group(): void
    {
        $other = Complex::factory()->create(['messenger_enabled' => true]);
        $stranger = User::factory()->create(['complex_id' => $other->id, 'role' => UserRole::Owner, 'is_active' => true]);
        $leaver = $this->person('رفته', '09129990104', UserRole::Owner);
        $suspended = $this->person('معلق', '09129990105', UserRole::Owner);
        app(MembershipLedger::class)->suspend($suspended, $this->complex->id, $this->manager);
        app(MembershipLedger::class)->end($leaver, ComplexMembership::END_REMOVED, $this->manager, $this->complex->id);

        // همه «همین حالا» دیده شده‌اند
        DB::table('users')->update(['last_seen_at' => now()]);

        $channel = $this->channel();
        $this->assertSame(3, Presence::onlineCount($channel), 'مدیر، مالک و مستأجر.');
        $this->assertSame(3, $this->info($this->manager, $channel)->json('counts.online'));
        $this->assertNotContains($stranger->id, collect($this->info($this->manager, $channel)->json('members'))->pluck('id'));

        // گروهِ مدیر برای واحدی دیگر: فقط مدیر، نه ساکنانِ واحدِ ده
        $elsewhere = Unit::factory()->create(['complex_id' => $this->complex->id, 'unit_number' => '20']);
        $group = app(ConversationDirectory::class)->createGroup($this->complex, 'طبقه‌ی دوم', [$elsewhere->id], true, $this->manager);
        $this->assertSame(1, Presence::onlineCount($group));

        // پرسشِ ۸ ثانیه‌ای همان شمار را دارد
        $this->assertSame(3, $this->as($this->owner)->getJson("/api/v1/messenger/conversations/{$channel->id}/messages")->json('onlineCount'));
    }

    /** 🔴 حضورِ یک نفر فقط به هم‌گفت‌وگوی او می‌رسد، فقط برای عضوِ فعال، و هرگز با سریال‌شدنِ حساب. */
    public function test_presence_reaches_only_the_other_side_of_a_one_to_one_chat(): void
    {
        DB::table('users')->update(['last_seen_at' => now()]);

        $ownerList = collect($this->list($this->owner))->keyBy('type');
        $this->assertNotNull($ownerList['pair']['peerOnlineUntil'], 'مالک مستأجرِ آنلاین را می‌بیند.');
        // ساکن در گفت‌وگوی خصوصی «مدیریتِ ساختمان» می‌بیند — یک نقش، نه یک نفر
        $this->assertNull($ownerList['user']['peerOnlineUntil']);
        $this->assertNull($ownerList['saved']['peerOnlineUntil']);
        $this->assertNull($ownerList['channel']['peerOnlineUntil']);
        $this->assertIsInt($this->as($this->owner)->getJson('/api/v1/messenger/conversations')->json('serverTime'));

        // مدیر حضورِ عضو را در گفت‌وگوی خصوصیِ همان عضو می‌بیند
        $this->as($this->owner)->postJson("/api/v1/messenger/conversations/{$ownerList['user']['id']}/messages", ['body' => 'سلام'])->assertCreated();
        $managerView = collect($this->list($this->manager))->firstWhere('id', $ownerList['user']['id']);
        $this->assertNotNull($managerView['peerOnlineUntil']);

        // و در خودِ رشته (پرسشِ ۸ ثانیه‌ای)
        $thread = $this->as($this->manager)->getJson("/api/v1/messenger/conversations/{$ownerList['user']['id']}/messages")->assertOk();
        $this->assertNotNull($thread->json('conversation.peerOnlineUntil'));
        $this->assertNull($thread->json('onlineCount'), 'گفت‌وگوی یک‌به‌یک شمارِ گروه ندارد.');

        // مستأجرِ معلق‌شده، هرچند همین حالا دیده شده، دیگر آنلاین نیست
        app(MembershipLedger::class)->suspend($this->tenant, $this->complex->id, $this->manager);
        DB::table('users')->update(['last_seen_at' => now()]);
        $this->assertNull(Presence::peers([Conversation::withoutGlobalScopes()->find($ownerList['pair']['id'])], $this->owner->fresh())[$ownerList['pair']['id']]);

        // «آخرین بار» هرگز با خودِ حساب بیرون نمی‌رود
        $this->assertArrayNotHasKey('last_seen_at', $this->owner->fresh()->toArray());
    }

    /** 🔴 حضور اجازه نمی‌دهد: عضوِ پایان‌یافته با «آخرین بارِ» تازه همچنان رد می‌شود و ضربانی نمی‌زند. */
    public function test_presence_grants_no_access_and_ended_members_send_no_heartbeat(): void
    {
        $channel = $this->channel();
        app(MembershipLedger::class)->end($this->owner, ComplexMembership::END_REMOVED, $this->manager, $this->complex->id);
        DB::table('users')->where('id', $this->owner->id)->update(['last_seen_at' => null]);

        $denied = $this->as($this->owner->fresh())->getJson("/api/v1/messenger/conversations/{$channel->id}/messages");
        $this->assertContains($denied->status(), [403, 404]);
        $this->assertNull($this->owner->fresh()->last_seen_at, 'درخواستِ ردشده ضربان نیست.');

        DB::table('users')->where('id', $this->owner->id)->update(['last_seen_at' => now()]);
        $again = $this->as($this->owner->fresh())->getJson("/api/v1/messenger/conversations/{$channel->id}/messages");
        $this->assertSame($denied->status(), $again->status(), 'آنلاین بودن دری باز نمی‌کند.');
        DB::table('users')->update(['last_seen_at' => now()]);
        $this->assertSame(2, Presence::onlineCount($channel), 'عضوِ رفته در شمار نیست.');
    }

    /** حضور مالِ عضویتِ فعال است: حسابِ بی‌مجتمع («حالتِ اولیه») و ادمینِ کل ضربان نمی‌زنند. */
    public function test_accounts_without_an_active_membership_write_no_presence(): void
    {
        $registrant = User::factory()->create(['complex_id' => null, 'role' => UserRole::Owner, 'is_active' => true]);
        $superAdmin = User::factory()->create(['complex_id' => null, 'role' => UserRole::SuperAdmin, 'is_active' => true]);

        foreach ([$registrant, $superAdmin] as $account) {
            $this->as($account)->getJson('/api/v1/notifications')->assertOk();
            $this->assertNull($account->fresh()->last_seen_at, $account->role->value);
        }

        // کنترلِ مثبت: عضوِ فعال با همان درخواست ضربان می‌زند
        $this->as($this->owner)->getJson('/api/v1/notifications')->assertOk();
        $this->assertNotNull($this->owner->fresh()->last_seen_at);
    }

    private function channel(): Conversation
    {
        app(TenantContext::class)->set(null);

        return app(ConversationDirectory::class)->channel($this->complex);
    }

    private function info(User $viewer, Conversation $conversation): TestResponse
    {
        return $this->as($viewer)->getJson("/api/v1/messenger/conversations/{$conversation->id}/info")->assertOk();
    }

    /** @return list<array<string, mixed>> */
    private function list(User $user): array
    {
        return $this->as($user)->getJson('/api/v1/messenger/conversations')->assertOk()->json('data');
    }

    private function person(string $name, string $phone, UserRole $role): User
    {
        return User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => $role, 'is_active' => true, 'name' => $name, 'phone' => $phone, 'can_message' => true,
        ]);
    }

    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();

        return $this->actingAs($user);
    }
}
