<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * محدودیت نرخ روی مسیرهای احراز هویت.
 *
 * بدون این‌ها حدس‌زدن رمز و کد پیامکی بی‌هزینه بود، و مهم‌تر: هر کسی
 * می‌توانست با درخواست انبوهِ کد، اعتبار پیامکِ سامانه را تمام کند.
 */
class AuthThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // شمارنده‌ها بین تست‌ها نباید نشت کنند
        RateLimiter::clear('login');
        $this->app['cache']->flush();
    }

    public function test_password_login_is_throttled_after_five_wrong_attempts(): void
    {
        User::create([
            'name' => 'کاربر', 'phone' => '09120001111', 'role' => UserRole::Owner,
            'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        // پنج تلاش نادرست: خطای اعتبارسنجی می‌گیرند ولی بسته نمی‌شوند
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['phone' => '09120001111', 'password' => 'wrong'])
                ->assertStatus(422);
        }

        // ششمی باید ۴۲۹ بگیرد، آن هم با پیام فارسی
        $response = $this->postJson('/api/login', ['phone' => '09120001111', 'password' => 'wrong'])
            ->assertStatus(429);

        $this->assertStringContainsString('بیش از حد مجاز', $response->json('message'));
        $this->assertIsInt($response->json('retryAfter'));
    }

    /** حتی رمز درست هم پس از بسته‌شدن باید رد شود. */
    public function test_throttle_also_blocks_a_correct_password(): void
    {
        User::create([
            'name' => 'کاربر', 'phone' => '09120002222', 'role' => UserRole::Owner,
            'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['phone' => '09120002222', 'password' => 'wrong']);
        }

        $this->postJson('/api/login', ['phone' => '09120002222', 'password' => 'secret123'])
            ->assertStatus(429);
    }

    /** شمارنده per-phone است، پس شماره‌ی دیگر نباید قربانی شود. */
    public function test_throttling_one_phone_does_not_block_another(): void
    {
        User::create([
            'name' => 'الف', 'phone' => '09120003333', 'role' => UserRole::Owner,
            'password' => Hash::make('secret123'), 'is_active' => true,
        ]);
        User::create([
            'name' => 'ب', 'phone' => '09120004444', 'role' => UserRole::Owner,
            'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['phone' => '09120003333', 'password' => 'wrong']);
        }

        $this->postJson('/api/login', ['phone' => '09120003333', 'password' => 'wrong'])->assertStatus(429);

        // شماره‌ی دوم هنوز آزاد است (تا سقف IP که ۲۰ در دقیقه است)
        $this->postJson('/api/login', ['phone' => '09120004444', 'password' => 'secret123'])
            ->assertOk();
    }

    /**
     * درخواست کد پیامکی سخت‌گیرانه‌تر است، چون هر بار پیامک واقعی می‌فرستد.
     *
     * از مسیر فراموشی رمز آزموده می‌شود که همان محدودیت `otp-request` را دارد؛
     * حتی با داده‌ی نادرست هم شمارنده بالا می‌رود، چون میدل‌ور محدودیت پیش از
     * کنترلر اجرا می‌شود.
     */
    public function test_otp_request_is_throttled(): void
    {
        $blocked = false;
        for ($i = 0; $i < 6; $i++) {
            $status = $this->postJson('/api/password/forgot', [
                'phone' => '09120005555', 'complex_name' => 'وجود ندارد', 'birth_date' => '1370-01-01',
            ])->status();

            if ($status === 429) {
                $blocked = true;
                break;
            }
        }

        $this->assertTrue($blocked, 'درخواست کد پیامکی باید پس از چند بار بسته شود.');
    }

    public function test_registration_is_throttled_per_ip(): void
    {
        $blocked = false;
        for ($i = 0; $i < 8; $i++) {
            $status = $this->postJson('/api/register', [
                'name' => 'کاربر تست',
                'phone' => '0912900'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'complex_name' => 'وجود ندارد',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
            ])->status();

            if ($status === 429) {
                $blocked = true;
                break;
            }
        }

        $this->assertTrue($blocked, 'ثبت‌نام باید پس از چند بار در ساعت بسته شود.');
    }
}
