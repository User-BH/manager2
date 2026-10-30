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
 * ورودِ بی‌رمز با کدِ پیامکی (۲۰۲۶-۱۰-۳۰)، دستگاهِ مورداعتماد و ثبت‌نام.
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

        $this->complex = Complex::create(['type' => 'residential',
            'name' => 'مجتمع ورود', 'slug' => 'login-'.uniqid(), 'currency' => 'toman',
            'charge_due_day' => 10, 'payment_gateway' => 'none',
        ]);

        $this->user = User::create([
            'complex_id' => $this->complex->id, 'name' => 'کاربر', 'phone' => '09120000010',
            'role' => UserRole::ComplexAdmin, 'password' => Hash::make('secret123'),
            'is_active' => true, 'birth_date' => '1370-01-01',
        ]);
    }

    /* --------------------- گام ۱: شماره --------------------- */

    public function test_the_phone_alone_does_not_log_in_yet_but_sends_a_code(): void
    {
        $response = $this->postJson('/api/login', [
            'phone' => '09120000010',
        ])->assertOk()->assertJsonPath('otpRequired', true);

        // هنوز واردنشده — نشست خالی است تا کد تایید شود
        $this->assertGuest();

        // در حالت تست کد برمی‌گردد
        $this->assertNotNull($response->json('dev_code'));
        $this->assertSame(6, strlen((string) $response->json('dev_code')));
    }

    /** شماره‌ی بی‌حساب همان پاسخ را می‌گیرد ولی کدی نمی‌رسد؛ هیچ کدی هم او را وارد نمی‌کند */
    public function test_an_unknown_phone_gets_no_code_and_cannot_sign_in(): void
    {
        $this->postJson('/api/login', ['phone' => '09129999999'])
            ->assertOk()->assertJsonPath('otpRequired', true)->assertJsonPath('dev_code', null);

        $this->postJson('/api/login/verify', ['code' => '000000'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['phone' => '09129999999']);
    }

    public function test_an_inactive_account_cannot_sign_in(): void
    {
        $this->user->update(['is_active' => false]);

        $this->postJson('/api/login', ['phone' => '09120000010'])
            ->assertOk()->assertJsonPath('dev_code', null);
        $this->postJson('/api/login/verify', ['code' => '000000'])->assertStatus(422);
        $this->assertGuest();
    }

    /* --------------------- گام ۲: کد پیامکی --------------------- */

    public function test_the_full_two_step_login_authenticates_the_user(): void
    {
        $code = $this->postJson('/api/login', [
            'phone' => '09120000010',
        ])->json('dev_code');

        $this->postJson('/api/login/verify', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('user.phone', '09120000010');

        $this->assertAuthenticatedAs($this->user->fresh());
    }

    /** رمزِ همراهِ شماره هیچ میان‌بُری نیست: کد همیشه لازم است */
    public function test_a_password_sent_along_the_phone_is_no_shortcut(): void
    {
        $this->postJson('/api/login', ['phone' => '09120000010', 'password' => 'secret123'])
            ->assertOk()->assertJsonPath('otpRequired', true)->assertJsonMissingPath('user');

        $this->assertGuest();
    }

    public function test_a_full_login_clears_otp_codes_so_an_immediate_relogin_is_not_blocked(): void
    {
        // ورودِ کاملِ اول
        $code = $this->postJson('/api/login', [
            'phone' => '09120000010',
        ])->json('dev_code');
        $this->postJson('/api/login/verify', ['code' => $code])->assertOk();

        // پس از ورودِ کامل، کدهای این شماره پاک می‌شوند تا «فاصله‌ی ارسال
        // مجدد» که از روی همین ردیف حساب می‌شد، جلوی ورودِ بعدی را نگیرد.
        $this->assertDatabaseCount('otp_codes', 0);

        // خروج و ورودِ بلافاصله دوباره: باید کدِ تازه بفرستد، نه خطای «کمی صبر کنید».
        $this->postJson('/api/logout')->assertOk();

        $this->postJson('/api/login', [
            'phone' => '09120000010',
        ])->assertOk()
            ->assertJsonPath('otpRequired', true)
            ->assertJsonPath('dev_code', fn ($code) => strlen((string) $code) === 6);
    }

    public function test_a_wrong_code_does_not_authenticate(): void
    {
        $this->postJson('/api/login', ['phone' => '09120000010']);

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
            'phone' => '09120000010', 'remember' => true,
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
            'phone' => '09120000010',
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
            'phone' => '09120000010',
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
            'phone' => '09120000010',
        ], $device->id.':'.$plain);

        // منقضی، پس باز هم کد می‌خواهد
        $response->assertOk()->assertJsonPath('otpRequired', true);
    }

    /* --------------------- بی «فراموشیِ رمز» --------------------- */

    public function test_there_is_no_password_reset_any_more(): void
    {
        $this->postJson('/api/password/forgot', ['phone' => '09120000010'])->assertNotFound();
        $this->postJson('/api/password/forgot/verify', ['code' => '000000'])->assertNotFound();
        $this->postJson('/api/password/reset', [
            'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ])->assertNotFound();

        // نشانیِ قدیمی (نشانک یا پیوندِ کهنه) به درِ ورود می‌رسد
        $this->get('/auth/forgot')->assertRedirect('/auth');
        $this->assertTrue(Hash::check('secret123', $this->user->fresh()->password));
    }

    /* --------------------- ثبت‌نام --------------------- */

    public function test_registration_records_that_terms_were_accepted(): void
    {
        // گام ۱: ارسال کد (هنوز حسابی ساخته نمی‌شود)
        $code = $this->postJson('/api/register', [
            'first_name' => 'کاربر',
            'last_name' => 'تازه',
            'phone' => '09120000077',
            'accept_terms' => true,
        ])->assertOk()->assertJsonPath('otpRequired', true)->json('dev_code');

        $this->assertDatabaseMissing('users', ['phone' => '09120000077']);

        // گام ۲: تایید کد → حساب ساخته می‌شود
        $this->postJson('/api/register/verify', ['code' => $code])->assertStatus(201);

        $created = User::where('phone', '09120000077')->firstOrFail();

        // تیک قوانین دیگر دکوری نیست؛ لحظه‌ی پذیرش در دیتابیس می‌ماند
        $this->assertNotNull($created->terms_accepted_at);

        /*
         * حساب **فعال** ساخته می‌شود و به هیچ مجتمعی وصل نیست (R21).
         *
         * پیش از این غیرفعال ساخته می‌شد با این توضیح که «تا تایید مدیر»، ولی
         * هیچ مسیرِ تاییدی وجود نداشت: خودش نمی‌توانست وارد شود و مدیر هم
         * نمی‌توانست اضافه‌اش کند (شماره یکتا بود). یعنی هر کسی که خودش
         * ثبت‌نام می‌کرد برای همیشه گیر می‌کرد.
         */
        $this->assertTrue((bool) $created->is_active);
        $this->assertNull($created->complex_id);
    }

    public function test_an_abandoned_registration_leaves_no_user(): void
    {
        // شماره را می‌زند و کد می‌گیرد، ولی کد را تایید نمی‌کند و منصرف می‌شود
        $this->postJson('/api/register', [
            'first_name' => 'منصرف',
            'last_name' => 'شده',
            'phone' => '09120000088',
            'accept_terms' => true,
        ])->assertOk()->assertJsonPath('otpRequired', true);

        // هیچ رکوردی در فهرست اعضا نباید بماند
        $this->assertDatabaseMissing('users', ['phone' => '09120000088']);
    }

    public function test_registration_is_refused_without_accepting_terms(): void
    {
        $this->postJson('/api/register', [
            'first_name' => 'کاربر',
            'last_name' => 'تازه',
            'phone' => '09120000078',
            'accept_terms' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('accept_terms');

        $this->assertDatabaseMissing('users', ['phone' => '09120000078']);
    }

    public function test_registration_no_longer_asks_for_a_complex(): void
    {
        // نام مجتمع دیگر پرسیده نمی‌شود؛ مدیر هنگام تایید تعیینش می‌کند
        $code = $this->postJson('/api/register', [
            'first_name' => 'بدون',
            'last_name' => 'مجتمع',
            'phone' => '09120000079',
            'accept_terms' => true,
        ])->assertOk()->json('dev_code');

        $this->postJson('/api/register/verify', ['code' => $code])->assertStatus(201);

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
