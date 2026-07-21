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

    public function test_login_with_phone_and_password(): void
    {
        $this->user();

        $this->postJson('/api/login', [
            'phone' => '0912 123 4567', // شکل غیراستاندارد؛ باید نرمال شود
            'password' => 'secret123',
        ])
            ->assertOk()
            ->assertJsonPath('user.phone', '09121234567');

        $this->assertAuthenticated();
    }

    public function test_login_with_wrong_password_fails(): void
    {
        $this->user();

        $this->postJson('/api/login', ['phone' => '09121234567', 'password' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_sign_in(): void
    {
        $this->user()->update(['is_active' => false]);

        $this->postJson('/api/login', ['phone' => '09121234567', 'password' => 'secret123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

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

        $response = $this->postJson('/api/login', [
            'phone' => '09121234567',
            'password' => 'secret123',
        ])->assertOk();

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
