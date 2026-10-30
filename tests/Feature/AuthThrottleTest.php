<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\OtpService;
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

    /** ورود با شماره (بی رمز، ۲۰۲۶-۱۰-۳۰): پنج درخواست در دقیقه برای هر شماره؛ ششمی ۴۲۹ با پیامِ فارسی */
    public function test_phone_login_is_throttled_after_five_requests(): void
    {
        $this->owner('09120001111');

        for ($i = 0; $i < 5; $i++) {
            // اولی کد می‌فرستد، بقیه «کمی صبر کنید» می‌گیرند — همه شمرده می‌شوند
            $this->assertContains($this->postJson('/api/login', ['phone' => '09120001111'])->status(), [200, 422]);
        }

        $response = $this->postJson('/api/login', ['phone' => '09120001111'])->assertStatus(429);

        $this->assertStringContainsString('بیش از حد مجاز', $response->json('message'));
        $this->assertIsInt($response->json('retryAfter'));
    }

    /** شمارنده per-phone است، پس شماره‌ی دیگر نباید قربانی شود. */
    public function test_throttling_one_phone_does_not_block_another(): void
    {
        $this->owner('09120003333');
        $this->owner('09120004444');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['phone' => '09120003333']);
        }
        $this->postJson('/api/login', ['phone' => '09120003333'])->assertStatus(429);

        // شماره‌ی دوم هنوز آزاد است (تا سقف IP که ۲۰ در دقیقه است)
        $this->postJson('/api/login', ['phone' => '09120004444'])->assertOk()->assertJsonPath('otpRequired', true);
    }

    /**
     * 🔴 پیامک‌باران (۲۰۲۶-۱۰-۳۰): بی رمز، دانستنِ شماره برای فرستادنِ پیامک کافی است. پنج کدِ تأییدنشده در یک ساعت سقف
     * است — برای شماره‌ی دارای حساب و بی‌حساب همان پیام — و ورودِ موفق شمارش را پاک می‌کند.
     */
    public function test_unverified_codes_are_capped_per_phone_and_a_real_login_resets_the_count(): void
    {
        $this->owner('09120006666');

        foreach (['09120006666', '09120007777'] as $phone) {
            for ($i = 0; $i < 5; $i++) {
                $this->travel(61)->seconds();
                RateLimiter::clear('login');
                $this->app['cache']->flush();
                $this->flushSession();
                $this->postJson('/api/login', ['phone' => $phone])->assertOk();
            }
            $this->travel(61)->seconds();
            $this->app['cache']->flush();
            $this->postJson('/api/login', ['phone' => $phone])->assertStatus(422)
                ->assertJsonPath('errors.phone.0', 'درخواستِ کد برای این شماره بیش از حد بوده است. یک ساعتِ دیگر دوباره تلاش کنید.');
        }

        // ساعتی بعد دوباره باز است؛ و ورودِ موفق شمارش را صفر می‌کند
        $this->travel(61)->minutes();
        $this->app['cache']->flush();
        $code = $this->postJson('/api/login', ['phone' => '09120006666'])->assertOk()->json('dev_code');
        $this->postJson('/api/login/verify', ['code' => $code])->assertOk();
        $this->assertSame(0, OtpCode::where('phone', '09120006666')->count());
    }

    /** کدِ مصرف‌نشده یک دقیقه فاصله می‌خواهد؛ کدِ تأییدشده (مثلاً تأییدِ دوباره) ورودِ بی‌درنگ از دستگاهِ دیگر را نمی‌بندد */
    public function test_only_an_unused_code_holds_the_resend_cooldown(): void
    {
        $this->owner('09120008888');
        $otp = $this->app->make(OtpService::class);

        $code = $otp->request('09120008888')['dev_code'];
        $this->postJson('/api/login', ['phone' => '09120008888'])->assertStatus(422)
            ->assertJsonPath('errors.phone.0', 'برای ارسال مجدد کد کمی صبر کنید.');

        $this->assertTrue($otp->verify('09120008888', $code));
        $this->postJson('/api/login', ['phone' => '09120008888'])->assertOk()->assertJsonPath('otpRequired', true);
    }

    /**
     * درخواست کد پیامکی سخت‌گیرانه‌تر است، چون هر بار پیامک واقعی می‌فرستد.
     *
     * از مسیرِ ثبت‌نام آزموده می‌شود که محدودیتِ `otp-request` را دارد؛ حتی با داده‌ی نادرست هم شمارنده بالا می‌رود، چون
     * میدل‌ور محدودیت پیش از کنترلر اجرا می‌شود.
     */
    public function test_otp_request_is_throttled(): void
    {
        $blocked = false;
        for ($i = 0; $i < 20; $i++) {
            $status = $this->postJson('/api/account-recovery/code', ['phone' => '09120005555'])->status();

            if ($status === 429) {
                $blocked = true;
                break;
            }
        }

        $this->assertTrue($blocked, 'درخواست کد پیامکی باید پس از چند بار بسته شود.');
    }

    private function owner(string $phone): User
    {
        return User::create([
            'name' => 'کاربر', 'phone' => $phone, 'role' => UserRole::Owner,
            'password' => Hash::make('secret123'), 'is_active' => true,
        ]);
    }

    public function test_registration_is_throttled_per_ip(): void
    {
        $blocked = false;
        for ($i = 0; $i < 8; $i++) {
            $status = $this->postJson('/api/register', [
                'first_name' => 'کاربر',
                'last_name' => 'آزمون',
                'phone' => '0912900'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'complex_name' => 'وجود ندارد',
            ])->status();

            if ($status === 429) {
                $blocked = true;
                break;
            }
        }

        $this->assertTrue($blocked, 'ثبت‌نام باید پس از چند بار در ساعت بسته شود.');
    }
}
