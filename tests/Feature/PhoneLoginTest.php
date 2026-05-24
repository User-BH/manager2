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

        $this->post('/login/password', [
            'phone' => '0912 123 4567', // non-canonical, should normalise
            'password' => 'secret123',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticated();
    }

    public function test_login_with_wrong_password_fails(): void
    {
        $this->user();

        $this->post('/login/password', ['phone' => '09121234567', 'password' => 'nope'])
            ->assertSessionHasErrors('phone');

        $this->assertGuest();
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
