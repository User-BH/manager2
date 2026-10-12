<?php

namespace Tests\Feature;

use App\Enums\AccountState;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\ComplexMembership;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\Membership;
use App\Services\Account\MembershipLedger;
use App\Support\SystemSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ثبت‌نام از پیوندِ دعوتِ مستقیم ← عضویت ← داشبوردِ واحد (مرحلهٔ ۱۰ · فاز ۵؛ بندهای ۲۴، ۹۰ و ۹۱).
 *
 * پیش از این دعوت‌شده پس از ثبت‌نام به داشبوردِ اولیه می‌رفت و آن‌جا «پیوستن» می‌زد؛ بندِ ۹۰ این برگشت را
 * منع می‌کند و می‌گوید «با پرچمِ کلاینت امن نشود؛ سرور وضعیتِ دعوت و عضویت را تأیید کند». هر آزمون یکی
 * از این شرط‌ها را می‌سنجد — از جمله اینکه هیچ چیزِ کلاینت عضویت نمی‌سازد.
 */
class DirectInvitationRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '09127770090';

    private Complex $complex;

    private User $manager;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create(['name' => 'مجتمع مستقیم']);
        $this->manager = User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true,
        ]);
        $this->unit = Unit::factory()->create(['complex_id' => $this->complex->id, 'unit_number' => '4']);
    }

    public function test_registering_from_the_link_makes_a_member_without_the_initial_dashboard(): void
    {
        [$invitation, $token] = $this->invite(UserRole::Tenant);

        $this->registerWith($token)
            ->assertCreated()
            ->assertJsonPath('joined', 'مجتمع مستقیم');

        $user = User::where('phone', self::PHONE)->sole();

        // عضویت از دعوت، نه از درخواست — بی تأییدِ دوباره (بندِ ۲۴)
        $membership = ComplexMembership::withoutGlobalScopes()->where('user_id', $user->id)->sole();
        $this->assertSame(ComplexMembership::ACTIVE, $membership->status);
        $this->assertSame(ComplexMembership::SOURCE_INVITATION, $membership->source);
        $this->assertSame($invitation->id, $membership->invitation_id);
        $this->assertSame(ComplexInvitation::ACCEPTED, $invitation->fresh()->status);
        $this->assertSame(0, ComplexInvitation::requests()->count());

        // واحدِ دعوت و وضعیتِ «عضو»: ورودِ بعدی مستقیم داشبوردِ واحد است، نه داشبوردِ اولیه
        $this->assertTrue($user->units()->withoutGlobalScopes()->whereKey($this->unit->id)->exists());
        $me = $this->actingAs($user->fresh())->getJson('/api/v1/me')->json('user');
        $this->assertSame(AccountState::Member->value, $me['accountState']);
        $this->assertSame('tenant', $me['role']);
        $this->assertSame($this->complex->id, $me['complex']['id']);
        $this->assertSame([], app(MembershipLedger::class)->violations());
        $this->assertDatabaseHas('audit_logs', ['action' => 'invitation.accepted']);
    }

    public function test_a_manager_invitation_makes_a_manager_at_registration(): void
    {
        [, $token] = $this->invite(UserRole::ComplexAdmin, unit: false);

        $this->registerWith($token)->assertCreated()->assertJsonPath('joined', 'مجتمع مستقیم');

        $me = $this->actingAs(User::where('phone', self::PHONE)->sole())->getJson('/api/v1/me')->json('user');
        $this->assertSame('complex_admin', $me['role']);
        $this->assertSame(AccountState::Member->value, $me['accountState']);
    }

    public function test_a_token_for_another_phone_is_ignored_and_the_invitation_waits_for_its_owner(): void
    {
        [$invitation, $token] = $this->invite(UserRole::Tenant, phone: '09127770091');

        $this->registerWith($token)->assertCreated()->assertJsonPath('joined', null);

        $this->assertInitial(User::where('phone', self::PHONE)->sole());
        $this->assertSame(ComplexInvitation::PENDING, $invitation->fresh()->status);
    }

    public function test_a_revoked_used_expired_or_made_up_token_still_registers_into_the_initial_state(): void
    {
        [$revoked, $revokedToken] = $this->invite(UserRole::Tenant);
        app(Membership::class)->revoke($revoked);

        foreach ([$revokedToken, str_repeat('a', 43), ''] as $i => $token) {
            $phone = '0912777010'.$i;
            $this->registerWith($token, $phone)->assertCreated()->assertJsonPath('joined', null);
            $this->assertInitial(User::where('phone', $phone)->sole());
        }

        // منقضی
        [, $expiredToken] = $this->invite(UserRole::Tenant, phone: '09127770110');
        $this->travel(8)->days();
        $this->registerWith($expiredToken, '09127770110')->assertCreated()->assertJsonPath('joined', null);
        $this->assertInitial(User::where('phone', '09127770110')->sole());
    }

    public function test_without_the_token_an_invited_registrant_stays_initial_and_the_invitation_waits(): void
    {
        // پیوند گم شد (مثلاً صفحه را از نو بار کرد): همان مسیرِ قبلی — دعوت در داشبوردِ اولیه منتظر است (بندِ ۹۱)
        [$invitation] = $this->invite(UserRole::Tenant);

        $this->registerWith(null)->assertCreated()->assertJsonPath('joined', null);

        $user = User::where('phone', self::PHONE)->sole();
        $this->assertInitial($user);
        $this->assertSame(1, $this->actingAs($user)->getJson('/api/v1/me')->json('user.pendingInvitations'));
        $this->assertSame(ComplexInvitation::PENDING, $invitation->fresh()->status);
    }

    public function test_nothing_the_client_sends_creates_membership(): void
    {
        /*
         * نه شناسه‌ی مجتمع، نه نقش، نه شناسه‌ی دعوت، نه «پرچمِ دعوت»: فقط توکنِ زنده‌ی همین شماره. دعوتِ زنده‌ی
         * همین شماره عمداً هست — بی آن، سروری که به `complex_id` یا `invitation_id` اعتماد کند هم چیزی برای
         * پذیرفتن پیدا نمی‌کرد و آزمون بی‌صدا سبز می‌ماند (خرابکاریِ ۱۰/۵).
         */
        [$invitation] = $this->invite(UserRole::Tenant);
        $code = $this->registerStart()->json('dev_code');
        $this->postJson('/api/register/verify', [
            'code' => $code,
            'complex_id' => $this->complex->id,
            'role' => 'complex_admin',
            'invited' => true,
            'invitation_id' => $invitation->id,
        ])->assertCreated()->assertJsonPath('joined', null);

        $this->assertInitial(User::where('phone', self::PHONE)->sole());
        $this->assertSame(ComplexInvitation::PENDING, $invitation->fresh()->status);
    }

    public function test_a_wrong_code_makes_no_account_and_does_not_spend_the_invitation(): void
    {
        [$invitation, $token] = $this->invite(UserRole::Tenant);
        $this->registerStart();

        $this->postJson('/api/register/verify', ['code' => '000000', 'invitation_token' => $token])
            ->assertUnprocessable();

        $this->assertSame(0, User::where('phone', self::PHONE)->count());
        $this->assertSame(ComplexInvitation::PENDING, $invitation->fresh()->status);
    }

    public function test_the_platform_otp_switch_does_not_let_registration_skip_its_code(): void
    {
        /*
         * پذیرشِ دعوت با `otp_disabled` بی کد است؛ پذیرش در ثبت‌نام فقط چون ثبت‌نام **همیشه** کدِ پیامکیِ
         * همان شماره را می‌خواهد هم‌ارزِ آن است. اگر روزی ثبت‌نام هم بی کد شود، این آزمون باید بشکند.
         */
        SystemSettings::set('otp_disabled', true);
        [$invitation, $token] = $this->invite(UserRole::Tenant);
        $this->registerStart();

        $this->postJson('/api/register/verify', ['code' => '000000', 'invitation_token' => $token])
            ->assertUnprocessable();
        $this->assertSame(ComplexInvitation::PENDING, $invitation->fresh()->status);
    }

    public function test_a_failed_placement_still_registers_and_keeps_the_invitation_visible(): void
    {
        // واحد پس از دعوت حذف شد: پذیرش رد می‌شود، ثبت‌نام نه
        [$invitation, $token] = $this->invite(UserRole::Tenant);
        $this->unit->delete();

        $this->registerWith($token)->assertCreated()->assertJsonPath('joined', null);

        $this->assertInitial(User::where('phone', self::PHONE)->sole());
        $this->assertSame(ComplexInvitation::PENDING, $invitation->fresh()->status);
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    /** @return array{0: ComplexInvitation, 1: string} */
    private function invite(UserRole $role, string $phone = self::PHONE, bool $unit = true): array
    {
        return app(Membership::class)->invite($this->complex, $phone, $role, $unit ? $this->unit : null, $this->manager);
    }

    private function registerStart(string $phone = self::PHONE): TestResponse
    {
        return $this->postJson('/api/register', [
            'first_name' => 'دعوت',
            'last_name' => 'شده',
            'phone' => $phone,
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
            'accept_terms' => true,
        ])->assertOk();
    }

    private function registerWith(?string $token, string $phone = self::PHONE): TestResponse
    {
        $code = $this->registerStart($phone)->json('dev_code');

        return $this->postJson('/api/register/verify', array_filter([
            'code' => $code,
            'invitation_token' => $token,
        ], fn ($v) => $v !== null));
    }

    private function assertInitial(User $user): void
    {
        $this->assertNull($user->complex_id);
        $this->assertSame(0, ComplexMembership::withoutGlobalScopes()->where('user_id', $user->id)->count());
        $this->assertSame(
            AccountState::Initial->value,
            $this->actingAs($user)->getJson('/api/v1/me')->json('user.accountState'),
        );
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }
}
