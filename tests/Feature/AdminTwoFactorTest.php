<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\AdminTwoFactor;
use App\Services\Auth\OtpService;
use App\Services\Auth\PasswordConfirmation;
use App\Services\Auth\Totp;
use App\Support\SystemSettings;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * عاملِ دومِ سوپر ادمین: نام کاربری + رمز + TOTP + کدهای بازیابیِ یک‌بارمصرف (دستورِ یکپارچه‌ی ۲۰۲۶-۱۰-۲۵، بخشِ ۵).
 *
 * هر آزمون یکی از الزام‌های همان بخش است، با تمرکز بر راه‌های دورزدن: شناسه‌ی دیگر، `otp_disabled`، مسیرِ پیامکی،
 * بازیابیِ رمز، replay، کدِ بازیابیِ مصرف‌شده، تلاشِ پرتعداد با نشستِ تازه، نشستِ نیمه‌کاره روی Production، و نشتِ کلید.
 */
class AdminTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Secret123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['cache']->flush();
    }

    /**
     * ⚠️ درخواست با `env = production` میزبان‌های مجاز را **ایستا** روی `Request` می‌نشاند (`TrustHosts`) و به آزمون‌های
     * بعدیِ همین پردازه نشت می‌کند (همان پاک‌سازیِ `PreviewEnvironmentTest`).
     */
    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    /* ── راه‌اندازی ────────────────────────────────────────────────────── */

    public function test_enrolment_needs_the_password_window_and_a_correct_code_and_shows_the_codes_once(): void
    {
        $admin = $this->admin();

        // بی تأییدِ تازه‌ی رمز نه
        $this->actingAs($admin)->postJson('/api/v1/profile/two-factor/setup')->assertStatus(403);

        $setup = $this->confirmed($admin)->postJson('/api/v1/profile/two-factor/setup')->assertOk()->json();
        $this->assertSame(32, strlen($setup['secret']));
        $this->assertStringStartsWith('otpauth://totp/Sakena:sakena.admin?', $setup['uri']);

        // کدِ نادرست فعال نمی‌کند
        $this->confirmed($admin)->postJson('/api/v1/profile/two-factor/confirm', ['code' => $this->wrong($setup['secret'])])->assertStatus(422);
        $this->assertFalse(app(AdminTwoFactor::class)->enabled($admin->fresh()));

        $codes = $this->confirmed($admin)->postJson('/api/v1/profile/two-factor/confirm', ['code' => Totp::at($setup['secret'], Totp::step())])
            ->assertOk()->json('recoveryCodes');
        $this->assertCount(10, $codes);
        $this->assertCount(10, array_unique($codes));

        $status = $this->actingAs($admin)->getJson('/api/v1/profile/two-factor')->assertOk();
        $status->assertJson(['enabled' => true, 'recoveryCodesRemaining' => 10]);
        // وضعیت نه کلید دارد نه کد
        $this->assertStringNotContainsString($setup['secret'], $status->getContent());
        $this->assertStringNotContainsString($codes[0], $status->getContent());

        $this->assertTrue(AuditLog::where('action', 'two_factor.enabled')->exists());
    }

    public function test_the_secret_and_recovery_codes_are_encrypted_hidden_and_never_serialised(): void
    {
        [$admin, $secret, $codes] = $this->enrolled();

        $raw = User::withoutGlobalScopes()->toBase()->where('id', $admin->id)->first();
        $this->assertNotSame($secret, $raw->two_factor_secret, 'کلید بی رمزنگاری ذخیره شد.');
        $this->assertStringNotContainsString($secret, (string) $raw->two_factor_secret);
        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, (string) $raw->two_factor_recovery_codes);
        }

        $serialised = $admin->fresh()->toArray();
        foreach (['two_factor_secret', 'two_factor_pending_secret', 'two_factor_recovery_codes', 'two_factor_last_step'] as $column) {
            $this->assertArrayNotHasKey($column, $serialised);
        }
        $this->assertStringNotContainsString($secret, (string) json_encode($serialised));

        $me = $this->actingAs($admin->fresh())->getJson('/api/v1/me')->assertOk()->getContent();
        $this->assertStringNotContainsString($secret, (string) $me);
        $this->assertStringNotContainsString('two_factor_secret', (string) $me);

        $this->assertStringNotContainsString($secret, (string) json_encode(AuditLog::all()->toArray()), 'کلید به audit log رسید.');
    }

    /* ── ورود ──────────────────────────────────────────────────────────── */

    public function test_login_by_username_or_phone_always_asks_for_the_app_code_and_sends_no_sms(): void
    {
        [$admin, $secret] = $this->enrolled();

        foreach ([
            ['identifier_type' => 'username', 'username' => 'sakena.admin'],
            ['phone' => '09120000001'],
        ] as $i => $identifier) {
            $this->flushSession();
            $this->app['auth']->forgetGuards();

            $response = $this->postJson('/api/v1/login', $identifier + ['password' => self::PASSWORD])->assertOk();
            $response->assertJson(['twoFactorRequired' => true])->assertJsonMissing(['otpRequired' => true]);
            $this->assertNull($response->json('dev_code'), 'پیامکی فرستاده شد.');
            $this->assertGuest();

            $this->postJson('/api/v1/login/two-factor', ['code' => $this->wrong($secret)])->assertStatus(422);
            $this->assertGuest();

            // هر ورود کدِ گامِ خودش (کدِ پذیرفته‌شده دوباره پذیرفته نمی‌شود)
            $this->postJson('/api/v1/login/two-factor', ['code' => Totp::at($secret, Totp::step() + $i)])
                ->assertOk()->assertJsonPath('user.id', $admin->id);
            $this->assertAuthenticatedAs($admin->fresh());
            $this->postJson('/api/v1/logout')->assertOk();
        }
    }

    public function test_disabling_sms_codes_does_not_disable_the_admin_second_factor(): void
    {
        [, $secret] = $this->enrolled();
        SystemSettings::set('otp_disabled', true);

        $this->postJson('/api/v1/login', ['identifier_type' => 'username', 'username' => 'sakena.admin', 'password' => self::PASSWORD])
            ->assertOk()->assertJson(['twoFactorRequired' => true]);
        $this->assertGuest();

        $this->postJson('/api/v1/login/two-factor', ['code' => Totp::at($secret, Totp::step())])->assertOk();
        $this->assertAuthenticated();
    }

    public function test_a_used_code_cannot_be_replayed(): void
    {
        [, $secret] = $this->enrolled();
        $code = Totp::at($secret, Totp::step());

        $this->startLogin();
        $this->postJson('/api/v1/login/two-factor', ['code' => $code])->assertOk();
        $this->postJson('/api/v1/logout')->assertOk();

        $this->startLogin();
        $this->postJson('/api/v1/login/two-factor', ['code' => $code])->assertStatus(422);
        $this->assertGuest();
    }

    public function test_each_recovery_code_works_exactly_once(): void
    {
        [$admin, , $codes] = $this->enrolled();

        $this->startLogin();
        $this->postJson('/api/v1/login/two-factor', ['recovery_code' => strtoupper($codes[3])])
            ->assertOk()->assertJson(['recoveryCodesRemaining' => 9]);
        $this->postJson('/api/v1/logout')->assertOk();

        $this->startLogin();
        $this->postJson('/api/v1/login/two-factor', ['recovery_code' => $codes[3]])->assertStatus(422);
        $this->assertGuest();

        $this->assertSame(9, app(AdminTwoFactor::class)->remainingRecoveryCodes($admin->fresh()));
        $this->assertTrue(AuditLog::where('action', 'two_factor.recovery_code_used')->exists());
    }

    public function test_five_wrong_codes_end_the_challenge(): void
    {
        [, $secret] = $this->enrolled();

        $this->startLogin();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/login/two-factor', ['code' => $this->wrong($secret)])->assertStatus(422);
        }

        // چالش تمام شد: کدِ درست هم بی گامِ رمز پذیرفته نمی‌شود
        $this->postJson('/api/v1/login/two-factor', ['code' => Totp::at($secret, Totp::step())])
            ->assertStatus(422)->assertJsonPath('errors.code.0', 'مهلت ورود تمام شده است. دوباره با رمز عبور وارد شوید.');
        $this->assertGuest();
    }

    public function test_the_account_lock_survives_fresh_sessions(): void
    {
        [, $secret] = $this->enrolled();

        // ده کدِ نادرست در پنج نشستِ تازه، هر کدام از IP ِ دیگری (هر نشست دو تلاش)
        for ($session = 0; $session < 5; $session++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$session}"]);
            $this->startLogin();
            $this->postJson('/api/v1/login/two-factor', ['code' => $this->wrong($secret)])->assertStatus(422);
            $this->postJson('/api/v1/login/two-factor', ['code' => $this->wrong($secret)])->assertStatus(422);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1']);
        $this->startLogin();
        $this->postJson('/api/v1/login/two-factor', ['code' => Totp::at($secret, Totp::step())])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_the_sms_steps_cannot_complete_a_totp_challenge(): void
    {
        $this->enrolled();
        $this->startLogin();

        $this->postJson('/api/v1/login/verify', ['code' => '123456'])->assertStatus(422);
        $this->postJson('/api/v1/login/resend')->assertStatus(422);
        $this->assertGuest();
    }

    /**
     * چالشِ TOTP که آغاز شد، با کدِ پیامکی تمام نمی‌شود — حتی اگر میانِ دو گام عاملِ دوم بازنشانی شود و کدِ پیامکیِ
     * معتبری برای همان شماره از مسیرِ دیگری (فراموشیِ رمز) گرفته شده باشد.
     */
    public function test_a_started_totp_challenge_never_completes_with_an_sms_code(): void
    {
        [$admin] = $this->enrolled();
        $this->startLogin();

        app(AdminTwoFactor::class)->reset($admin->fresh());
        $sms = app(OtpService::class)->issue('09120000001', deliver: true)['dev_code'];
        $this->assertNotNull($sms);

        $this->postJson('/api/v1/login/verify', ['code' => $sms])->assertStatus(422);
        $this->assertGuest();
    }

    public function test_a_totp_step_cannot_be_used_without_the_password_step(): void
    {
        [, $secret] = $this->enrolled();

        $this->postJson('/api/v1/login/two-factor', ['code' => Totp::at($secret, Totp::step())])->assertStatus(422);
        $this->assertGuest();
    }

    public function test_resetting_the_password_by_sms_does_not_sign_the_admin_in(): void
    {
        [$admin] = $this->enrolled();

        $code = $this->postJson('/api/v1/password/forgot', ['phone' => '09120000001'])->assertOk()->json('dev_code');
        $this->postJson('/api/v1/password/forgot/verify', ['code' => $code])->assertOk();
        $this->postJson('/api/v1/password/reset', ['password' => 'Changed123', 'password_confirmation' => 'Changed123'])
            ->assertOk()->assertJsonPath('user', null);

        $this->assertGuest();
        $this->assertTrue(Hash::check('Changed123', $admin->fresh()->password));
        // ورود با رمزِ تازه باز هم کدِ برنامه می‌خواهد
        $this->postJson('/api/v1/login', ['identifier_type' => 'username', 'username' => 'sakena.admin', 'password' => 'Changed123'])
            ->assertOk()->assertJson(['twoFactorRequired' => true]);
        $this->assertGuest();
    }

    /* ── تعویض و کدهای تازه ────────────────────────────────────────────── */

    public function test_rotating_needs_the_current_code_and_keeps_the_old_key_until_confirmed(): void
    {
        [$admin, $old] = $this->enrolled();

        $this->confirmed($admin)->postJson('/api/v1/profile/two-factor/setup')->assertStatus(422);
        $new = $this->confirmed($admin)->postJson('/api/v1/profile/two-factor/setup', ['code' => Totp::at($old, Totp::step() + 1)])
            ->assertOk()->json('secret');
        $this->assertNotSame($old, $new);

        // تا تأییدِ تازه، کلیدِ قبلی همچنان کلیدِ ورود است
        $this->assertSame($old, $admin->fresh()->two_factor_secret);

        $this->confirmed($admin)->postJson('/api/v1/profile/two-factor/confirm', ['code' => Totp::at($new, Totp::step())])->assertOk();
        $this->assertSame($new, $admin->fresh()->two_factor_secret);
        $this->assertTrue(AuditLog::where('action', 'two_factor.rotated')->exists());

        $this->startLogin();
        $this->postJson('/api/v1/login/two-factor', ['code' => Totp::at($old, Totp::step() + 1)])->assertStatus(422);
    }

    public function test_regenerating_recovery_codes_needs_the_current_code_and_voids_the_old_ones(): void
    {
        [$admin, $secret, $old] = $this->enrolled();

        $this->confirmed($admin)->postJson('/api/v1/profile/two-factor/recovery-codes')->assertStatus(422);
        $fresh = $this->confirmed($admin)->postJson('/api/v1/profile/two-factor/recovery-codes', ['code' => Totp::at($secret, Totp::step() + 1)])
            ->assertOk()->json('recoveryCodes');
        $this->assertCount(10, $fresh);

        $this->startLogin();
        $this->postJson('/api/v1/login/two-factor', ['recovery_code' => $old[0]])->assertStatus(422);
        $this->postJson('/api/v1/login/two-factor', ['recovery_code' => $fresh[0]])->assertOk();
    }

    public function test_only_a_super_admin_has_a_second_factor(): void
    {
        $owner = (new User)->forceFill([
            'name' => 'ساکن', 'phone' => '09121234567', 'role' => UserRole::Owner, 'password' => Hash::make(self::PASSWORD), 'is_active' => true,
        ]);
        $owner->save();

        $this->confirmed($owner)->getJson('/api/v1/profile/two-factor')->assertStatus(403);
        $this->confirmed($owner)->postJson('/api/v1/profile/two-factor/setup')->assertStatus(403);
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/login', ['phone' => '09121234567', 'password' => self::PASSWORD])
            ->assertOk()->assertJson(['otpRequired' => true])->assertJsonMissing(['twoFactorRequired' => true]);
    }

    /* ── Production ────────────────────────────────────────────────────── */

    /**
     * سوپر ادمینِ Production بی TOTP: با پیامک وارد می‌شود ولی تا راه‌اندازی به هیچ مسیرِ مدیریتی یا داده‌ای نمی‌رسد؛
     * پس از فعال‌سازی همه‌چیز باز است.
     */
    public function test_on_production_an_unenrolled_admin_session_reaches_only_the_setup(): void
    {
        $admin = $this->admin();
        $this->production();

        $this->actingAs($admin)->getJson('/api/v1/system/stats')->assertStatus(403)->assertJsonPath('code', 'two_factor.setup_required');
        $this->actingAs($admin)->getJson('/api/v1/system/members')->assertStatus(403);
        $this->actingAs($admin)->getJson('/api/v1/dashboard')->assertStatus(403);
        $this->actingAs($admin)->putJson('/api/v1/profile/password', [
            'current_password' => self::PASSWORD, 'password' => 'Other1234', 'password_confirmation' => 'Other1234',
        ])->assertStatus(403);

        $this->actingAs($admin)->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.twoFactorSetupRequired', true);
        $this->actingAs($admin)->getJson('/api/v1/profile/two-factor')->assertOk()->assertJson(['setupRequired' => true]);

        $secret = $this->confirmed($admin)->postJson('/api/v1/profile/two-factor/setup')->assertOk()->json('secret');
        $this->confirmed($admin)->postJson('/api/v1/profile/two-factor/confirm', ['code' => Totp::at($secret, Totp::step())])->assertOk();

        $this->actingAs($admin->fresh())->getJson('/api/v1/system/stats')->assertOk();
        $this->actingAs($admin->fresh())->getJson('/api/v1/me')->assertJsonPath('user.twoFactorSetupRequired', false);
    }

    public function test_on_production_an_unenrolled_admin_never_signs_in_with_the_password_alone(): void
    {
        $this->admin();
        $this->production();

        // با پیامک: فقط کدِ پیامکی، نه ورودِ مستقیم
        $this->postJson('/api/v1/login', ['identifier_type' => 'username', 'username' => 'sakena.admin', 'password' => self::PASSWORD])
            ->assertOk()->assertJson(['otpRequired' => true]);
        $this->assertGuest();

        // پیامک خاموش: ورود رد، نه «بی کد»
        $this->flushSession();
        SystemSettings::set('otp_disabled', true);
        $this->postJson('/api/v1/login', ['identifier_type' => 'username', 'username' => 'sakena.admin', 'password' => self::PASSWORD])
            ->assertStatus(422);
        $this->assertGuest();
    }

    /**
     * «حفره ها۲» بندِ ۱۸.۴ برای سوپر ادمینِ اصلی، روی Production: تغییرِ رمز از مسیرِ واقعی؛ رمزِ قبلی رد؛ رمزِ تازه هم
     * **بی TOTP وارد نمی‌کند**؛ hash؛ رمز در audit log نیست؛ عاملِ دوم سرِ جایش؛ حسابِ دیگر دست‌نخورده.
     */
    public function test_the_main_admin_changes_the_password_and_still_needs_the_authenticator(): void
    {
        [$admin] = $this->enrolled();
        $other = User::factory()->create(['password' => Hash::make('Other12345'), 'is_active' => true]);
        $this->production();

        $this->actingAs($admin)->putJson('/api/v1/profile/password', [
            'current_password' => self::PASSWORD, 'password' => 'NewSecret456', 'password_confirmation' => 'NewSecret456',
            'user_id' => $other->id,
        ])->assertOk();

        $fresh = $admin->fresh();
        $this->assertTrue(Hash::check('NewSecret456', $fresh->password));
        $this->assertStringNotContainsString('NewSecret456', (string) $fresh->getRawOriginal('password'));
        $this->assertTrue(Hash::check('Other12345', $other->fresh()->password), 'رمزِ حسابِ دیگری عوض شد.');
        $this->assertTrue(app(AdminTwoFactor::class)->enabled($fresh), 'تغییرِ رمز عاملِ دوم را برداشت.');
        $this->assertStringNotContainsString('NewSecret456', (string) json_encode(AuditLog::all()->toArray()));

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/login', ['identifier_type' => 'username', 'username' => 'sakena.admin', 'password' => self::PASSWORD])
            ->assertStatus(422);
        $this->postJson('/api/v1/login', ['identifier_type' => 'username', 'username' => 'sakena.admin', 'password' => 'NewSecret456'])
            ->assertOk()->assertJson(['twoFactorRequired' => true]);
        $this->assertGuest();
    }

    /* ── فرمانِ سرور ───────────────────────────────────────────────────── */

    public function test_the_server_command_enrols_once_and_resets_with_an_audit_trail(): void
    {
        $admin = $this->admin();

        $this->artisan('admin:two-factor', ['username' => 'sakena.admin', '--force' => true])
            ->expectsOutputToContain('کلیدِ Authenticator')
            ->assertSuccessful();
        $this->assertTrue(app(AdminTwoFactor::class)->enabled($admin->fresh()));
        $this->assertSame(10, app(AdminTwoFactor::class)->remainingRecoveryCodes($admin->fresh()));

        // دوباره نه — اول بازنشانی
        $this->artisan('admin:two-factor', ['username' => 'sakena.admin', '--force' => true])->assertFailed();

        $this->artisan('admin:two-factor', ['username' => 'sakena.admin', '--reset' => true, '--force' => true])->assertSuccessful();
        $this->assertFalse(app(AdminTwoFactor::class)->enabled($admin->fresh()));
        $this->assertNull($admin->fresh()->two_factor_secret);
        $this->assertTrue(AuditLog::where('action', 'two_factor.reset')->exists());

        // فقط سوپر ادمین
        (new User)->forceFill(['name' => 'ساکن', 'username' => 'not.admin', 'phone' => '09121234567', 'role' => UserRole::Owner,
            'password' => Hash::make(self::PASSWORD), 'is_active' => true])->save();
        $this->artisan('admin:two-factor', ['username' => 'not.admin', '--force' => true])->assertFailed();
    }

    /* ── کمکی ─────────────────────────────────────────────────────────── */

    private function admin(): User
    {
        $admin = (new User)->forceFill([
            'name' => 'سوپر ادمین', 'username' => 'sakena.admin', 'phone' => '09120000001', 'role' => UserRole::SuperAdmin,
            'password' => Hash::make(self::PASSWORD), 'is_active' => true,
        ]);
        $admin->save();

        return $admin;
    }

    /** @return array{0: User, 1: string, 2: list<string>} */
    private function enrolled(): array
    {
        $admin = $this->admin();
        $service = app(AdminTwoFactor::class);
        $secret = $service->begin($admin)['secret'];
        $codes = $service->confirm($admin->fresh(), Totp::at($secret, Totp::step() - 1));
        $this->assertNotNull($codes);

        return [$admin->fresh(), $secret, $codes];
    }

    private function startLogin(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/login', ['identifier_type' => 'username', 'username' => 'sakena.admin', 'password' => self::PASSWORD])
            ->assertOk()->assertJson(['twoFactorRequired' => true]);
    }

    private function confirmed(User $user): static
    {
        return $this->actingAs($user)->withSession([PasswordConfirmation::KEY => now()->timestamp]);
    }

    /** کدی که در پنجره‌ی ±۱ گام هیچ‌کدام از کدهای درست نیست */
    private function wrong(string $secret): string
    {
        $valid = array_map(fn (int $o) => Totp::at($secret, Totp::step() + $o), [-2, -1, 0, 1, 2]);
        for ($n = 0; ; $n++) {
            $candidate = str_pad((string) $n, 6, '0', STR_PAD_LEFT);
            if (! in_array($candidate, $valid, true)) {
                return $candidate;
            }
        }
    }

    private function production(): void
    {
        $this->app['env'] = 'production';
        $this->withoutMiddleware(PreventRequestForgery::class);
    }
}
