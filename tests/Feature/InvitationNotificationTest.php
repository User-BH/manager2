<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\ComplexMembership;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\JoinRequestNotification;
use App\Services\Account\Membership;
use App\Services\Account\MembershipLedger;
use App\Support\SessionContext;
use App\Support\SystemSettings;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * اعلانِ دعوت — مرحلهٔ ۱۳ · فاز ۵ (ایرادات ۱۱، بندهای ۳.۳ تا ۳.۱۲ و ۵.۱۳؛ تصمیمِ ۲.۳-۴).
 *
 * سارا در «آفتاب» مستأجر است؛ مدیرِ «نفیس» او را دعوت می‌کند. رویدادِ واقعی (`Membership::invite`) ← اعلانِ **حسابیِ**
 * همان حساب ← شمارِ خوانده‌نشده‌ی زنگوله و نشانِ آدمک ← فهرست در هر زمینه، از جمله داشبوردِ اولیه ← جزئیاتِ واقعی ←
 * پذیرش یا رد. اعلان عضویت نیست؛ و هیچ شناسه‌ای از بیرون اختیاری نمی‌سازد.
 *
 * شماره‌گذاری همان پانزده آزمونِ بندِ ۳.۱۲ است؛ ۱۴ (موبایل و دسکتاپ) در `tests/e2e/invitations.spec.ts`.
 */
class InvitationNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Complex $aftab;

    private Complex $nafis;

    private User $sara;

    private User $managerNafis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aftab = Complex::factory()->create(['name' => 'مجتمع مسکونی آفتاب']);
        $this->nafis = Complex::factory()->create(['name' => 'مجتمع تجاری نفیس']);
        $this->sara = User::factory()->create(['complex_id' => $this->aftab->id, 'role' => UserRole::Tenant, 'is_active' => true, 'phone' => '09127775501']);
        $this->managerNafis = User::factory()->create([
            'complex_id' => $this->nafis->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true, 'phone' => '09127775502',
            'first_name' => 'کاوه', 'last_name' => 'مدیری',
        ]);
        SystemSettings::set('otp_disabled', true);
    }

    /** ۱ و ۲: درخواستِ مدیر ← اعلانِ حسابی برای همان حساب، با داده‌ی واقعی؛ نه برای کسِ دیگر. */
    public function test_a_managers_invitation_notifies_exactly_the_invited_account(): void
    {
        $bystander = User::factory()->create(['complex_id' => $this->aftab->id, 'role' => UserRole::Owner, 'is_active' => true, 'phone' => '09127775503']);
        $invitation = $this->invite();

        $notification = $this->sara->notifications()->sole();
        $this->assertSame('invitation.received', $notification->data['type']);
        $this->assertSame($invitation->id, $notification->data['invitationId']);
        $this->assertArrayNotHasKey('complexId', $notification->data, 'اعلانِ دعوت حسابی است، نه مجتمعی.');
        $this->assertSame('دعوت به «مجتمع تجاری نفیس»', $notification->data['title']);
        $this->assertStringContainsString('کاوه مدیری', $notification->data['body']);
        $this->assertStringContainsString('مدیر مجتمع', $notification->data['body']);

        $this->assertSame(0, $bystander->notifications()->count());
        $this->assertSame(0, $this->managerNafis->notifications()->count());
        // شماره‌ی بی‌حساب: دعوت هست، اعلانی نه
        app(Membership::class)->invite($this->nafis, '09127775599', UserRole::ComplexAdmin, null, $this->managerNafis);
        $this->assertSame(1, DatabaseNotification::count());
    }

    /** ۳: نشانِ خوانده‌نشده — زنگوله و آدمک — از داده‌ی واقعی؛ و در هر زمینه‌ی همان حساب. */
    public function test_the_unread_badge_counts_the_real_notification_in_every_context(): void
    {
        $this->invite();

        foreach ([null, $this->sara->id.':initial'] as $pin) {
            $this->as($this->sara, $pin)->getJson('/api/v1/me')->assertJsonPath('user.unreadInvitations', 1)->assertJsonPath('user.pendingInvitations', 1);
            $bell = $this->as($this->sara, $pin)->getJson('/api/v1/notifications?limit=5')->assertOk();
            $this->assertSame(1, $bell->json('unreadCount'));
            $this->assertSame(1, $bell->json('unreadInvitations'));
            $this->assertSame(['personal', '/invitations'], [$bell->json('items.0.kind'), $bell->json('items.0.link')]);
        }
    }

    /** ۴ و ۵: در داشبوردِ اولیه باز می‌شود و جزئیاتِ واقعیِ دعوت را دارد. */
    public function test_the_initial_dashboard_opens_the_real_invitation_details(): void
    {
        $unit = Unit::factory()->create(['complex_id' => $this->nafis->id, 'unit_number' => 'ن-۴']);
        $this->invite(UserRole::Tenant, $unit);
        $initial = $this->sara->id.':initial';

        $history = $this->as($this->sara, $initial)->getJson('/api/v1/notifications/history')->assertOk();
        $this->assertContains('دعوت به «مجتمع تجاری نفیس»', array_column($history->json('items'), 'title'));

        $details = $this->as($this->sara, $initial)->getJson('/api/v1/invitations')->assertOk()->json('data.0');
        $this->assertSame(['مجتمع تجاری نفیس', 'کاوه مدیری'], [$details['complexName'], $details['invitedBy']]);
        $this->assertStringContainsString('ن-۴', (string) $details['unitLabel']);
        $this->assertSame(UserRole::Tenant->label(), $details['roleLabel']);
    }

    /** ۳.۵: خواندنِ اعلان، دیدنِ جزئیات و نامِ مجتمع هیچ عضویت یا دسترسی‌ای نمی‌سازد. */
    public function test_reading_the_notification_is_not_membership(): void
    {
        $this->invite();
        $id = $this->sara->notifications()->sole()->id;

        $this->as($this->sara)->postJson("/api/v1/notifications/personal/{$id}/read")->assertOk();
        $this->as($this->sara)->getJson('/api/v1/invitations')->assertOk();

        $this->assertFalse(ComplexMembership::withoutGlobalScopes()->where('user_id', $this->sara->id)->where('complex_id', $this->nafis->id)->exists());
        $this->as($this->sara)->postJson('/api/v1/account/context', ['complex_id' => $this->nafis->id])->assertStatus(422);
        $this->as($this->sara)->getJson('/api/v1/me')->assertJsonPath('user.complex.id', $this->aftab->id);
    }

    /** ۶ و ۹: پذیرشِ معتبر (با کدِ تازه) عضویت می‌سازد؛ دومی نه — و نشان می‌رود، هرچند اعلان خوانده نشده. */
    public function test_accepting_a_valid_invitation_once_and_only_once(): void
    {
        $invitation = $this->invite();

        $this->accept($invitation)->assertOk();
        $this->assertSame(1, ComplexMembership::withoutGlobalScopes()->active()->where('user_id', $this->sara->id)->where('complex_id', $this->nafis->id)->count());
        $this->as($this->sara)->getJson('/api/v1/me')->assertJsonPath('user.unreadInvitations', 0)->assertJsonPath('user.pendingInvitations', 0);
        $this->assertNull($this->sara->notifications()->sole()->read_at, 'وضعیتِ اعلان مستقل از وضعیتِ دعوت است.');

        // پذیرشِ دوباره / بازپخش
        $this->accept($invitation)->assertNotFound();
        $this->assertSame(1, ComplexMembership::withoutGlobalScopes()->where('user_id', $this->sara->id)->where('complex_id', $this->nafis->id)->count());
    }

    /** ۷ و ۸: ردِ دعوتِ معتبر؛ ردِ دعوتِ نامعتبر (مالِ کسِ دیگر، باطل، منقضی) ۴۰۴ است و چیزی را عوض نمی‌کند. */
    public function test_declining_valid_and_invalid_invitations(): void
    {
        $invitation = $this->invite();

        $this->as($this->sara)->postJson("/api/v1/invitations/{$invitation->id}/decline")->assertOk();
        $this->assertSame(ComplexInvitation::DECLINED, $invitation->fresh()->status);
        $this->assertFalse(ComplexMembership::withoutGlobalScopes()->where('user_id', $this->sara->id)->where('complex_id', $this->nafis->id)->exists());
        $this->as($this->sara)->getJson('/api/v1/me')->assertJsonPath('user.unreadInvitations', 0);

        // دوباره رد کردنِ همان، و ردِ دعوتِ دیگری
        $this->as($this->sara)->postJson("/api/v1/invitations/{$invitation->id}/decline")->assertNotFound();
        $other = app(Membership::class)->invite($this->nafis, '09127775504', UserRole::ComplexAdmin, null, $this->managerNafis)[0];
        $this->as($this->sara)->postJson("/api/v1/invitations/{$other->id}/decline")->assertNotFound();
        $this->assertSame(ComplexInvitation::PENDING, $other->fresh()->status);

        // دعوتِ منقضی دیگر نشان نمی‌سازد و پذیرفته نمی‌شود
        $expired = $this->invite();
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->as($this->sara)->getJson('/api/v1/me')->assertJsonPath('user.unreadInvitations', 0);
        $this->accept($expired)->assertNotFound();
    }

    /** ۱۰: اعلانِ سارا را کسِ دیگری نه می‌بیند و نه علامت می‌زند؛ شناسه‌ی اعلان اختیاری نمی‌سازد. */
    public function test_another_account_cannot_reach_the_notification(): void
    {
        $this->invite();
        $id = $this->sara->notifications()->sole()->id;
        $intruder = User::factory()->create(['complex_id' => $this->aftab->id, 'role' => UserRole::Owner, 'is_active' => true, 'phone' => '09127775505']);

        $this->as($intruder)->postJson("/api/v1/notifications/personal/{$id}/read")->assertNotFound();
        $this->assertNull(DatabaseNotification::find($id)->read_at);
        $bell = $this->as($intruder)->getJson('/api/v1/notifications?limit=10')->assertOk();
        $this->assertNotContains('n:'.$id, array_column($bell->json('items'), 'id'));
        $this->assertSame(0, $bell->json('unreadInvitations'));
    }

    /** ۱۱: اعلانِ مجتمعیِ «آفتاب» فقط در آفتاب؛ اعلانِ دعوت (حسابی) در آفتاب، نفیس و داشبوردِ اولیه — بی نسبت دادن به مجتمعی. */
    public function test_community_notifications_stay_apart_and_the_invitation_is_everywhere(): void
    {
        $this->sara->notify(new JoinRequestNotification(ComplexInvitation::create([
            'complex_id' => $this->aftab->id, 'phone' => '09127775506', 'role' => UserRole::Owner,
            'direction' => ComplexInvitation::REQUEST, 'status' => ComplexInvitation::PENDING,
        ]), 'متقاضیِ آفتاب'));
        $this->invite();
        // سارا عضوِ دومِ «باغ» هم هست تا زمینه‌ی دومِ عضویتی داشته باشد
        $bagh = Complex::factory()->create(['name' => 'مجتمع باغ']);
        app(MembershipLedger::class)->start($this->sara, $bagh, UserRole::Owner, ComplexMembership::SOURCE_DIRECT, activate: false);

        $titles = fn (?string $pin) => array_column($this->as($this->sara, $pin)->getJson('/api/v1/notifications/history')->json('items'), 'title');

        $this->assertContains('درخواستِ تازه‌ی عضویت', $titles($this->sara->id.':'.$this->aftab->id));
        $this->assertNotContains('درخواستِ تازه‌ی عضویت', $titles($this->sara->id.':'.$bagh->id));
        $this->assertNotContains('درخواستِ تازه‌ی عضویت', $titles($this->sara->id.':initial'));
        foreach ([$this->sara->id.':'.$this->aftab->id, $this->sara->id.':'.$bagh->id, $this->sara->id.':initial'] as $pin) {
            $this->assertContains('دعوت به «مجتمع تجاری نفیس»', $titles($pin), $pin);
        }
    }

    /** ۱۲ و ۱۳: خواندن شمار را درست کم می‌کند و پس از بارگذاریِ دوباره همان می‌ماند — نه دوباره‌شماری. */
    public function test_reading_updates_the_counts_and_survives_a_refresh(): void
    {
        $this->invite();
        $id = $this->sara->notifications()->sole()->id;

        $read = $this->as($this->sara)->postJson("/api/v1/notifications/personal/{$id}/read")->assertOk();
        $this->assertSame([0, 0], [$read->json('unreadCount'), $read->json('unreadInvitations')]);

        foreach (range(1, 2) as $refresh) {
            $this->as($this->sara)->getJson('/api/v1/me')->assertJsonPath('user.unreadInvitations', 0);
            $this->assertSame(0, $this->as($this->sara)->getJson('/api/v1/notifications')->json('unreadCount'), "بارِ {$refresh}");
        }
        // دعوت هنوز زنده است: خواندن ≠ پاسخ
        $this->as($this->sara)->getJson('/api/v1/me')->assertJsonPath('user.pendingInvitations', 1);

        // دعوتِ دوباره (دعوتِ قبلی باطل می‌شود) اعلانِ تازه است و نشان دوباره ۱
        $this->invite();
        $this->as($this->sara)->getJson('/api/v1/me')->assertJsonPath('user.unreadInvitations', 1);
    }

    /** سوپر ادمین دعوت نمی‌گیرد و نشانی ندارد. */
    public function test_the_super_admin_is_never_invited_nor_badged(): void
    {
        $super = User::factory()->create(['complex_id' => null, 'role' => UserRole::SuperAdmin, 'is_active' => true, 'phone' => '09127775507']);

        $this->expectExceptionMessage('ادمینِ سامانه');
        try {
            app(Membership::class)->invite($this->nafis, $super->phone, UserRole::ComplexAdmin, null, $this->managerNafis);
        } finally {
            $this->assertSame(0, $super->notifications()->count());
            $this->as($super)->getJson('/api/v1/me')->assertJsonPath('user.unreadInvitations', 0);
        }
    }

    /* ── ابزار ── */

    private function invite(UserRole $role = UserRole::ComplexAdmin, ?Unit $unit = null): ComplexInvitation
    {
        return app(Membership::class)->invite($this->nafis, $this->sara->phone, $role, $unit, $this->managerNafis)[0];
    }

    private function accept(ComplexInvitation $invitation): TestResponse
    {
        $code = $this->as($this->sara)->postJson("/api/v1/invitations/{$invitation->id}/code")->json('devCode');

        return $this->as($this->sara)->postJson("/api/v1/invitations/{$invitation->id}/accept", ['code' => (string) $code]);
    }

    /** مرورگری با سنجاقِ خودش (همان قراردادِ `SessionContextTest::device`) */
    private function as(User $user, ?string $pin = null): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();
        $this->defaultCookies = [];
        $this->withCredentials();
        if ($pin !== null) {
            $this->withCookie(SessionContext::COOKIE, $pin);
        }

        return $this->actingAs(User::withoutGlobalScopes()->findOrFail($user->id));
    }
}
