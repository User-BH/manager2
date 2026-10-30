<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhoneLoginTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'name' => 'تست', 'phone' => '09121234567', 'role' => UserRole::Tenant,
            'password' => Hash::make('secret123'), 'is_active' => true,
        ]);
    }

    /** ورود بی رمز (۲۰۲۶-۱۰-۳۰): شماره ← کدِ پیامکی ← ورود */
    public function test_login_with_phone_and_sms_code_needs_no_password(): void
    {
        $this->user();

        // گام ۱: فقط شماره؛ کدِ پیامکی می‌رود، هنوز وارد نشده
        $code = $this->postJson('/api/login', [
            'phone' => '0912 123 4567', // شکل غیراستاندارد؛ باید نرمال شود
        ])
            ->assertOk()
            ->assertJsonPath('otpRequired', true)
            ->json('dev_code');

        $this->assertNotNull($code);
        $this->assertGuest();

        // گام ۲: تایید کد، ورود نهایی
        $this->postJson('/api/login/verify', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('user.phone', '09121234567');

        $this->assertAuthenticated();
    }

    /** رمزِ فرستاده‌شده نه کمکی می‌کند نه مانعی: گامِ دوم همیشه کد است */
    public function test_a_password_is_ignored_and_never_signs_in(): void
    {
        $this->user();

        $this->postJson('/api/login', ['phone' => '09121234567', 'password' => 'secret123'])
            ->assertOk()->assertJsonPath('otpRequired', true)->assertJsonMissingPath('user');
        $this->assertGuest();
    }

    public function test_a_wrong_code_does_not_sign_in(): void
    {
        $this->user();

        $this->postJson('/api/login', ['phone' => '09121234567'])->assertOk();
        $this->postJson('/api/login/verify', ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertGuest();
    }

    public function test_an_invalid_phone_is_refused_before_any_code(): void
    {
        $this->postJson('/api/login', ['phone' => '02112345678'])
            ->assertStatus(422)
            ->assertJsonPath('errors.phone.0', 'شماره تلفن همراه معتبر نیست.');
    }

    /** حسابِ غیرفعال همان پاسخِ «کد فرستاده شد» را می‌گیرد ولی کدی نمی‌گیرد و وارد نمی‌شود */
    public function test_inactive_user_cannot_sign_in(): void
    {
        $this->user()->update(['is_active' => false]);

        $response = $this->postJson('/api/login', ['phone' => '09121234567'])->assertOk();
        $this->assertNull($response->json('dev_code'), 'برای حسابِ غیرفعال کد فرستاده شد.');

        $this->postJson('/api/login/verify', ['code' => '123456'])->assertStatus(422);
        $this->assertGuest();
    }

    public function test_logout_ends_the_session(): void
    {
        $this->actingAs($this->user())->postJson('/api/logout')->assertOk();

        $this->assertGuest();
    }

    /**
     * ورود، نشست را regenerate می‌کند و توکن CSRF عوض می‌شود. چون SPA صفحه را
     * رفرش نمی‌کند، توکن تازه باید در پاسخ برگردد تا کلاینت بتواند به‌روزش کند.
     *
     * در عمل لاراول ۱۳ درخواست‌های same-origin مرورگر را از روی هدر
     * Sec-Fetch-Site می‌پذیرد و به توکن نمی‌رسد؛ این برای مرورگرهای قدیمی‌تر
     * و هر کلاینتی است که آن هدر را نمی‌فرستد.
     */
    public function test_login_returns_a_fresh_csrf_token(): void
    {
        $this->user();

        // توکن تازه در گام دوم (ورود نهایی) برمی‌گردد، جایی که نشست regenerate می‌شود
        $code = $this->postJson('/api/login', ['phone' => '09121234567'])->json('dev_code');

        $response = $this->postJson('/api/login/verify', ['code' => $code])->assertOk();

        $token = $response->json('csrfToken');

        $this->assertIsString($token);
        $this->assertNotEmpty($token);
        $this->assertSame(session()->token(), $token);
    }

    public function test_csrf_token_endpoint_is_available_to_guests(): void
    {
        $this->getJson('/api/csrf-token')
            ->assertOk()
            ->assertJsonStructure(['csrfToken']);
    }

    public function test_otp_request_and_verify_logs_user_in(): void
    {
        $user = $this->user();
        $otp = app(OtpService::class);

        // Log driver is the default in testing, so the plain code is returned.
        $result = $otp->request('09121234567');
        $this->assertTrue($result['ok']);
        $this->assertNotNull($result['dev_code']);

        $this->assertTrue($otp->verify('09121234567', $result['dev_code']));
        // A used code cannot be reused.
        $this->assertFalse($otp->verify('09121234567', $result['dev_code']));
    }

    public function test_otp_rejects_wrong_code(): void
    {
        $this->user();
        app(OtpService::class)->request('09121234567');

        $this->assertFalse(app(OtpService::class)->verify('09121234567', '00000'));
    }
}
