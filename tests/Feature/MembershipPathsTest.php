<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\ComplexMembership;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\MembershipLedger;
use App\Support\SystemSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * دو مسیرِ عضویت و امنیتِ دعوت — مرحلهٔ ۱۲ · فاز ۳ (بندهای ۱۲–۱۵ ِ سندِ هسته).
 *
 * آزمون‌های پایه در `JoinRequestTest`، `SecureInvitationTest`، `InvitationConcurrencyTest`، `DirectInvitationRoutingTest`
 * و `PublicJoinTest` اند؛ این‌جا شکاف‌هایی که ۱۲/۳ با کد سنجید و بست:
 *
 *   • ردِ درخواست بی قفل و بی سنجشِ «در انتظار» بود: ردِ درخواستِ پذیرفته‌شده سابقه‌اش را «ردشده» می‌کرد.
 *   • یافتنِ مدیر با شماره از ردیفِ حساب بود، نه عضویتِ فعال: مدیری که زمینه‌ی پیش‌فرضش جای دیگری است پیدا نمی‌شد،
 *     مدیرِ دو مجتمع فقط یکی را داشت و مجتمعِ تعلیق‌شده (که QR ِ آن ۴۰۴ است) درخواست می‌گرفت.
 *   • دعوتِ بی انقضا (ردیفِ پیش از ۹/۶) برای همیشه زنده شمرده می‌شد.
 *   • پذیرشِ دعوت با `otp_disabled` بی کد بود؛ بندِ ۱۵ کدِ پیامکی را برای پذیرش الزامی می‌داند.
 */
class MembershipPathsTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $manager;

    private User $applicant;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create(['name' => 'مجتمع آفتاب']);
        $this->manager = User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => UserRole::ComplexAdmin, 'phone' => '09120001111', 'is_active' => true,
        ]);
        $this->unit = Unit::factory()->create(['complex_id' => $this->complex->id]);
        $this->applicant = User::factory()->create([
            'complex_id' => null, 'role' => UserRole::Owner, 'phone' => '09125556666', 'is_active' => true,
        ]);
    }

    /* ── بندهای ۱۲ و ۱۴: پاسخِ مدیر به درخواست ─────────────────────────── */

    /** 🔴 ردِ درخواستِ پذیرفته‌شده سابقه را «ردشده» می‌کرد و عضویتِ فعال با سابقه‌ی «رد» می‌ماند. */
    public function test_rejecting_an_approved_request_is_refused_and_changes_nothing(): void
    {
        $request = $this->sendRequest();
        $this->actingAs($this->manager)->postJson("/api/v1/join-requests/{$request->id}/approve", ['role' => 'owner', 'unit_id' => $this->unit->id])->assertOk();

        $this->actingAs($this->manager)->postJson("/api/v1/join-requests/{$request->id}/reject")
            ->assertUnprocessable()->assertJsonPath('code', 'invitation.not_pending');

        $this->assertSame(ComplexInvitation::ACCEPTED, $request->fresh()->status);
        $this->assertSame(ComplexMembership::ACTIVE, $this->membership($this->applicant, $this->complex)?->status);
    }

    public function test_a_request_is_answered_once(): void
    {
        $request = $this->sendRequest();

        $this->actingAs($this->manager)->postJson("/api/v1/join-requests/{$request->id}/reject")->assertOk();
        $answeredAt = $request->fresh()->responded_at;

        $this->actingAs($this->manager)->postJson("/api/v1/join-requests/{$request->id}/reject")->assertUnprocessable();
        $this->actingAs($this->manager)->postJson("/api/v1/join-requests/{$request->id}/approve", ['role' => 'owner', 'unit_id' => $this->unit->id])->assertUnprocessable();

        $this->assertSame(ComplexInvitation::DECLINED, $request->fresh()->status);
        $this->assertEquals($answeredAt, $request->fresh()->responded_at);
        $this->assertNull($this->membership($this->applicant, $this->complex));
    }

    /** دعوتِ مدیر از مسیرِ «ردِ درخواست» بسته نمی‌شود — دو مسیر جدا اند (بندِ ۱۴). */
    public function test_the_request_reject_route_cannot_close_an_invitation(): void
    {
        $invitation = ComplexInvitation::issue([
            'complex_id' => $this->complex->id, 'phone' => $this->applicant->phone, 'unit_id' => $this->unit->id,
            'role' => UserRole::Owner, 'invited_by' => $this->manager->id,
        ])[0];

        $this->actingAs($this->manager)->postJson("/api/v1/join-requests/{$invitation->id}/reject")->assertNotFound();
        $this->assertSame(ComplexInvitation::PENDING, $invitation->fresh()->status);
    }

    /* ── بندِ ۱۲: یافتنِ مدیر با شماره از عضویتِ فعال ─────────────────── */

    /** 🔴 مدیری که پیش‌فرضِ حسابش مجتمعِ دیگری است (۱۲/۱) با شماره‌اش پیدا نمی‌شد. */
    public function test_a_manager_whose_default_is_another_community_is_still_found(): void
    {
        $home = Complex::factory()->create(['name' => 'مجتمع خانه']);
        app(MembershipLedger::class)->start($this->manager, $home, UserRole::Owner, ComplexMembership::SOURCE_DIRECT);
        $this->assertSame($home->id, $this->manager->fresh()->complex_id, 'پیش‌فرضِ حساب حالا مجتمعِ دیگر است.');

        $this->actingAs($this->applicant)->getJson('/api/v1/join-requests/lookup?phone=09120001111')
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('complexName', 'مجتمع آفتاب')
            ->assertJsonPath('complexes', [['id' => $this->complex->id, 'name' => 'مجتمع آفتاب']]);

        $this->actingAs($this->applicant)->postJson('/api/v1/join-requests', ['phone' => '09120001111'])->assertStatus(202);
        $this->assertSame(1, $this->pendingRequests($this->complex));
        $this->assertSame(0, $this->pendingRequests($home), 'مجتمعی که او فقط ساکنش است درخواست نمی‌گیرد.');
    }

    /** 🔴 مدیرِ دو مجتمع: متقاضی خودش مجتمع را برمی‌گزیند؛ بی انتخاب، درخواست به مجتمعِ حدسی نمی‌رود. */
    public function test_a_manager_of_two_communities_lets_the_applicant_choose(): void
    {
        $second = Complex::factory()->create(['name' => 'مجتمع مهتاب']);
        app(MembershipLedger::class)->start($this->manager, $second, UserRole::ComplexAdmin, ComplexMembership::SOURCE_DIRECT, activate: false);
        $foreign = Complex::factory()->create();

        $this->actingAs($this->applicant)->getJson('/api/v1/join-requests/lookup?phone=09120001111')
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('complexName', null)
            ->assertJsonCount(2, 'complexes');

        $this->actingAs($this->applicant)->postJson('/api/v1/join-requests', ['phone' => '09120001111'])
            ->assertUnprocessable()->assertJsonPath('code', 'join_request.choose_complex');
        $this->actingAs($this->applicant)->postJson('/api/v1/join-requests', ['phone' => '09120001111', 'complex_id' => $foreign->id])
            ->assertUnprocessable()->assertJsonPath('code', 'join_request.manager_not_found');
        $this->actingAs($this->applicant)->postJson('/api/v1/join-requests', ['phone' => '09120001111', 'complex_id' => [$second->id]])
            ->assertUnprocessable();
        $this->assertSame(0, ComplexInvitation::query()->requests()->count());

        $this->actingAs($this->applicant)->postJson('/api/v1/join-requests', ['phone' => '09120001111', 'complex_id' => $second->id])->assertStatus(202);
        $this->assertSame(1, $this->pendingRequests($second));
        $this->assertSame(0, $this->pendingRequests($this->complex));
    }

    /** مجتمعِ تعلیق‌شده با شماره‌ی مدیر هم درخواست نمی‌گیرد — همان قاعده‌ی QR (`PublicJoinLink::resolve`). */
    public function test_a_suspended_community_takes_no_request_by_its_managers_phone(): void
    {
        $this->complex->forceFill(['is_active' => false])->save();

        $this->actingAs($this->applicant)->getJson('/api/v1/join-requests/lookup?phone=09120001111')
            ->assertOk()->assertJsonPath('found', false)->assertJsonPath('complexes', []);
        $this->actingAs($this->applicant)->postJson('/api/v1/join-requests', ['phone' => '09120001111'])
            ->assertUnprocessable()->assertJsonPath('code', 'join_request.manager_not_found');
    }

    /** مدیری که عضویتِ مدیریتش معلق یا تمام شده مدیر نیست. */
    public function test_a_suspended_or_ended_management_is_not_found(): void
    {
        app(MembershipLedger::class)->suspend($this->manager, $this->complex->id);
        $this->actingAs($this->applicant)->getJson('/api/v1/join-requests/lookup?phone=09120001111')->assertJsonPath('found', false);

        app(MembershipLedger::class)->resume($this->manager, $this->complex->id);
        $this->actingAs($this->applicant)->getJson('/api/v1/join-requests/lookup?phone=09120001111')->assertJsonPath('found', true);

        app(MembershipLedger::class)->end($this->manager, ComplexMembership::END_REMOVED, null, $this->complex->id);
        $this->actingAs($this->applicant)->getJson('/api/v1/join-requests/lookup?phone=09120001111')->assertJsonPath('found', false);
        $this->actingAs($this->applicant)->postJson('/api/v1/join-requests', ['phone' => '09120001111'])->assertUnprocessable();
    }

    /* ── بندِ ۱۵: امنیتِ دعوت ──────────────────────────────────────────── */

    /** 🔴 دعوتِ بی انقضا (ردیفِ پیش از ۹/۶) برای همیشه زنده بود و پذیرفته می‌شد. */
    public function test_an_invitation_without_an_expiry_is_never_live(): void
    {
        $legacy = ComplexInvitation::create([
            'complex_id' => $this->complex->id, 'user_id' => $this->applicant->id, 'phone' => $this->applicant->phone,
            'unit_id' => $this->unit->id, 'role' => UserRole::Owner, 'direction' => ComplexInvitation::INVITE,
            'invited_by' => $this->manager->id, 'status' => ComplexInvitation::PENDING,
        ]);
        $this->assertNull($legacy->expires_at);
        $this->assertFalse($legacy->isLive());

        SystemSettings::set('otp_disabled', false);
        $this->actingAs($this->applicant)->getJson('/api/v1/invitations')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->applicant)->postJson("/api/v1/invitations/{$legacy->id}/code")->assertNotFound();
        $this->actingAs($this->applicant)->postJson("/api/v1/invitations/{$legacy->id}/accept", ['code' => '123456'])->assertNotFound();

        $this->assertNull($this->membership($this->applicant, $this->complex));
        $this->assertSame(ComplexInvitation::PENDING, $legacy->fresh()->status);
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    private function sendRequest(): ComplexInvitation
    {
        $this->actingAs($this->applicant)->postJson('/api/v1/join-requests', ['phone' => '09120001111'])->assertStatus(202);

        return ComplexInvitation::query()->requests()->where('user_id', $this->applicant->id)->sole();
    }

    private function pendingRequests(Complex $complex): int
    {
        return ComplexInvitation::query()->requests()->pending()->where('complex_id', $complex->id)->count();
    }

    private function membership(User $user, Complex $complex): ?ComplexMembership
    {
        return ComplexMembership::withoutGlobalScopes()
            ->where('user_id', $user->id)->where('complex_id', $complex->id)->whereIn('status', ComplexMembership::LIVE)->first();
    }
}
