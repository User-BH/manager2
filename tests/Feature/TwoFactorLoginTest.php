<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\Auth\TrustedDeviceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ورود دومرحله‌ای، دستگاهِ مورداعتماد و بازیابی رمز.
 *
 * درایور پیامک در تست «log» است، پس OtpService کد را در پاسخ (`dev_code`)
 * برمی‌گرداند و بدون پیامک واقعی می‌توان کل جریان را آزمود.
 */
class TwoFactorLoginTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::create([
            'name' => 'مجتمع ورود', 'slug' => 'login-'.uniqid(), 'currency' => 'toman',
            'charge_due_day' => 10, 'payment_gateway' => 'none',
        ]);

        $this->user = User::create([
            'complex_id' => $this->complex->id, 'name' => 'کاربر', 'phone' => '09120000010',
            'role' => UserRole::ComplexAdmin, 'password' => Hash::make('secret123'),
            'is_active' => true, 'birth_date' => '1370-01-01',
        ]);
    }

    /* --------------------- گام ۱: رمز عبور --------------------- */

    public function test_correct_password_does_not_log_in_yet_but_sends_a_code(): void
    {
        $response = $this->postJson('/api/login', [
            'phone' => '09120000010', 'password' => 'secret123',
        ])->assertOk()->assertJsonPath('otpRequired', true);

        // هنوز واردنشده — نشست خالی است تا کد تایید شود
        $this->assertGuest();

        // در حالت تست کد برمی‌گردد
        $this->assertNotNull($response->json('dev_code'));
        $this->assertSame(6, strlen((string) $response->json('dev_code')));
    }

    public function test_a_wrong_password_is_rejected_without_sending_a_code(): void
    {
        $this->postJson('/api/login', [
            'phone' => '09120000010', 'password' => 'wrong-pass',
        ])->assertStatus(422)->assertJsonValidationErrors('phone');

        $this->assertDatabaseCount('otp_codes', 0);
    }

    public function test_an_inactive_account_cannot_start_login(): void
    {
        $this->user->update(['is_active' => false]);

        $this->postJson('/api/login', [
            'phone' => '09120000010', 'password' => 'secret123',
        ])->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    /* --------------------- گام ۲: کد پیامکی --------------------- */

    public function test_the_full_two_step_login_authenticates_the_user(): void
    {
        $code = $this->postJson('/api/login', [
            'phone' => '09120000010', 'password' => 'secret123',
        ])->json('dev_code');

        $this->postJson('/api/login/verify', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('user.phone', '09120000010');

        $this->assertAuthenticatedAs($this->user->fresh());
    }

    public function test_a_wrong_code_does_not_authenticate(): void
    {
        $this->postJson('/api/login', ['phone' => '09120000010', 'password' => 'secret123']);

        $this->postJson('/api/login/verify', ['code' => '000000'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $this->assertGuest();
    }

    public function test_verifying_without_a_pending_login_is_refused(): void
    {
        $this->postJson('/api/login/verify', ['code' => '123456'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    /* --------------------- دستگاه مورداعتماد --------------------- */

    public function test_remember_me_issues_a_trusted_device(): void
    {
        $code = $this->postJson('/api/login', [
            'phone' => '09120000010', 'password' => 'secret123', 'remember' => true,
        ])->json('dev_code');

        $this->postJson('/api/login/verify', ['code' => $code])
            ->assertOk()
            ->assertCookie(TrustedDeviceService::COOKIE);

        $this->assertSame(1, $this->user->trustedDevices()->count());
        // انقضا حدود ۱۰ روز بعد است
        $device = $this->user->trustedDevices()->first();
        $this->assertTrue($device->expires_at->between(now()->addDays(9), now()->addDays(11)));
    }

    public function test_a_trusted_device_skips_the_second_step_entirely(): void
    {
        $cookie = $this->issueTrustedDeviceCookie();

        $response = $this->jsonWithDevice('POST', '/api/login', [
            'phone' => '09120000010', 'password' => 'secret123',
        ], $cookie);

        // بدون otpRequired مستقیم وارد شد و هیچ کدی هم فرستاده نشد
        $response->assertOk()->assertJsonPath('user.phone', '09120000010');
        $this->assertNull($response->json('otpRequired'));
        $this->assertDatabaseCount('otp_codes', 0);
        $this->assertAuthenticatedAs($this->user->fresh());
    }

    public function test_a_trusted_device_belonging_to_another_user_does_not_skip(): void
    {
        $other = User::create([
            'complex_id' => $this->complex->id, 'name' => 'دیگری', 'phone' => '09120000099',
            'role' => UserRole::Owner, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);
        $plain = 'z'.Str::random(47);
        $device = TrustedDevice::create([
            'user_id' => $other->id, 'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays(10),
        ]);

        // کوکیِ دستگاهِ کاربرِ دیگر نباید مرحله‌ی دوم را برای این کاربر رد کند
        $this->jsonWithDevice('POST', '/api/login', [
            'phone' => '09120000010', 'password' => 'secret123',
        ], $device->id.':'.$plain)->assertOk()->assertJsonPath('otpRequired', true);
    }

    public function test_logout_revokes_the_trusted_device(): void
    {
        $cookie = $this->issueTrustedDeviceCookie();
        $this->assertSame(1, TrustedDevice::count());

        $this->jsonWithDevice('POST', '/api/logout', [], $cookie)->assertOk();

        // دستگاه از دیتابیس پاک شده — خروج واقعاً اعتماد را باطل می‌کند
        $this->assertSame(0, TrustedDevice::count());
    }

    public function test_an_expired_trusted_device_does_not_skip_the_code(): void
    {
        $plain = 'x'.Str::random(47);
        $device = TrustedDevice::create([
            'user_id' => $this->user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->subDay(),   // منقضی
        ]);

        $response = $this->jsonWithDevice('POST', '/api/login', [
            'phone' => '09120000010', 'password' => 'secret123',
        ], $device->id.':'.$plain);

        // منقضی، پس باز هم کد می‌خواهد
        $response->assertOk()->assertJsonPath('otpRequired', true);
    }

    /* --------------------- بازیابی رمز --------------------- */

    public function test_forgot_password_only_needs_the_phone(): void
    {
        $this->postJson('/api/password/forgot', ['phone' => '09120000010'])
            ->assertOk()
            ->assertJsonPath('dev_code', fn ($code) => strlen((string) $code) === 6);
    }

    public function test_forgot_password_refuses_an_unknown_phone(): void
    {
        $this->postJson('/api/password/forgot', ['phone' => '09129999999'])
            ->assertStatus(422)->assertJsonValidationErrors('phone');

        // برای شماره‌ی ناشناس هیچ پیامکی فرستاده نمی‌شود
        $this->assertDatabaseCount('otp_codes', 0);
    }

    public function test_forgot_password_refuses_an_inactive_account(): void
    {
        $this->user->update(['is_active' => false]);

        $this->postJson('/api/password/forgot', ['phone' => '09120000010'])
            ->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_the_full_password_reset_flow_changes_the_password(): void
    {
        $code = $this->postJson('/api/password/forgot', ['phone' => '09120000010'])
            ->assertOk()->json('dev_code');

        $this->postJson('/api/password/forgot/verify', ['code' => $code])->assertOk();

        // پس از ثبت رمز تازه، سرور خودش کاربر را وارد می‌کند و برمی‌گرداند؛
        // هویتش با کد پیامکی اثبات شده، پس پیامکِ دومرحله‌ایِ دیگر لازم نیست.
        $this->postJson('/api/password/reset', [
            'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ])->assertOk()->assertJsonPath('user.phone', '09120000010');

        $this->assertAuthenticatedAs($this->user->fresh());

        // رمز واقعاً عوض شده. (ورودِ فوری را نمی‌آزماییم چون پیامکِ دومرحله‌ایِ
        // ورود به فاصله‌ی کوتاه پس از پیامکِ بازیابی به سقفِ ارسال مجدد می‌خورد؛
        // این خودش رفتار درست است.)
        $fresh = $this->user->fresh();
        $this->assertTrue(Hash::check('newpass123', $fresh->password));
        $this->assertFalse(Hash::check('secret123', $fresh->password));
    }

    public function test_reset_is_refused_before_the_code_is_verified(): void
    {
        $this->postJson('/api/password/forgot', ['phone' => '09120000010'])->assertOk();

        // بدون تایید کد، تغییر رمز رد می‌شود
        $this->postJson('/api/password/reset', [
            'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_reset_revokes_trusted_devices(): void
    {
        TrustedDevice::create([
            'user_id' => $this->user->id,
            'token_hash' => hash('sha256', 'whatever'),
            'expires_at' => now()->addDays(5),
        ]);

        $code = $this->postJson('/api/password/forgot', ['phone' => '09120000010'])->json('dev_code');
        $this->postJson('/api/password/forgot/verify', ['code' => $code])->assertOk();
        $this->postJson('/api/password/reset', [
            'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ])->assertOk();

        // تغییر رمز یعنی «به هیچ دستگاه قبلی اعتماد نکن»
        $this->assertSame(0, TrustedDevice::count());
    }

    /* --------------------- ثبت‌نام --------------------- */

    public function test_registration_records_that_terms_were_accepted(): void
    {
        $this->postJson('/api/register', [
            'name' => 'کاربر تازه',
            'phone' => '09120000077',
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
            'accept_terms' => true,
        ])->assertStatus(201);

        $created = User::where('phone', '09120000077')->firstOrFail();

        // تیک قوانین دیگر دکوری نیست؛ لحظه‌ی پذیرش در دیتابیس می‌ماند
        $this->assertNotNull($created->terms_accepted_at);
        // و حساب تا تایید مدیر غیرفعال است
        $this->assertFalse((bool) $created->is_active);
    }

    public function test_registration_is_refused_without_accepting_terms(): void
    {
        $this->postJson('/api/register', [
            'name' => 'کاربر تازه',
            'phone' => '09120000078',
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
            'accept_terms' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('accept_terms');

        $this->assertDatabaseMissing('users', ['phone' => '09120000078']);
    }

    public function test_registration_no_longer_asks_for_a_complex(): void
    {
        // نام مجتمع دیگر پرسیده نمی‌شود؛ مدیر هنگام تایید تعیینش می‌کند
        $this->postJson('/api/register', [
            'name' => 'بدون مجتمع',
            'phone' => '09120000079',
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
            'accept_terms' => true,
        ])->assertStatus(201);

        $this->assertNull(User::where('phone', '09120000079')->firstOrFail()->complex_id);
    }

    /** یک کوکی دستگاهِ مورداعتمادِ معتبرِ تازه می‌سازد (ردیف + مقدار خام). */
    private function issueTrustedDeviceCookie(): string
    {
        $plain = 'y'.Str::random(47);
        $device = TrustedDevice::create([
            'user_id' => $this->user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays(10),
        ]);

        return $device->id.':'.$plain;
    }

    /**
     * درخواست JSON همراه کوکیِ دستگاه.
     *
     * چرا call() و نه withCookie()->postJson()؟ چون در این نسخه‌ی لاراول،
     * withCookie و withUnencryptedCookie کوکی را به درخواست نمی‌رسانند، ولی
     * پارامتر cookies در call() می‌رساند (خودش را با یک روت آزمایشی سنجیدم).
     */
    private function jsonWithDevice(string $method, string $uri, array $body, string $cookie): TestResponse
    {
        return $this->call(
            $method, $uri, [],
            [TrustedDeviceService::COOKIE => $cookie],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode($body),
        );
    }
}
