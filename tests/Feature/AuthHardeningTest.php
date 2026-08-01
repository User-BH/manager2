<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\Auth\TrustedDeviceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * سخت‌سازی احراز هویت (R18).
 *
 * تمرکز روی چیزهایی است که «کار می‌کنند» ولی در لحظه‌ی بد کوتاه می‌آیند:
 * تغییرِ رمزی که مهاجم را بیرون نمی‌کند، و پنجره‌ی اعتمادی که هیچ‌وقت
 * آزموده نشده بود.
 */
class AuthHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create();
        $this->user = User::create([
            'complex_id' => $this->complex->id,
            // نامِ متمایز: «ساکن» زیررشته‌ی نامِ برند («ساکنا») است و تستِ نشت را بی‌معنا می‌کرد
            'name' => 'بهرام‌زاده‌فرد',
            'phone' => '09120000010',
            'role' => UserRole::Owner,
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
    }

    /* ── تغییر رمز باید اعتماد را باطل کند ──────────────────────────────── */

    /**
     * رایج‌ترین واکنش به «رمزم لو رفته» تغییر رمز است.
     *
     * اگر آن کار دستگاه‌های مورداعتماد را باطل نکند، مهاجمی که کوکی دارد تا
     * ۱۰ روز واردشده می‌ماند — یعنی همان کاری که کاربر برای نجات خودش کرد،
     * هیچ اثری نداشت. مسیرِ «فراموشی رمز» این را رعایت می‌کرد و این مسیر نه.
     */
    public function test_changing_the_password_revokes_every_trusted_device(): void
    {
        TrustedDevice::create([
            'user_id' => $this->user->id,
            'token_hash' => hash('sha256', Str::random(48)),
            'expires_at' => now()->addDays(10),
        ]);

        $this->actingAs($this->user)
            ->putJson('/api/v1/profile/password', [
                'current_password' => 'secret123',
                'password' => 'brandnew123',
                'password_confirmation' => 'brandnew123',
            ])
            ->assertOk();

        $this->assertSame(0, TrustedDevice::count());
    }

    public function test_a_revoked_device_can_no_longer_skip_the_code(): void
    {
        // اثباتِ رفتاری، نه فقط شمارشِ ردیف‌ها
        $plain = 'k'.Str::random(47);
        $device = TrustedDevice::create([
            'user_id' => $this->user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays(10),
        ]);
        $cookie = $device->id.':'.$plain;

        $this->actingAs($this->user)->putJson('/api/v1/profile/password', [
            'current_password' => 'secret123',
            'password' => 'brandnew123',
            'password_confirmation' => 'brandnew123',
        ])->assertOk();

        $this->loginWithDevice($cookie, 'brandnew123')
            ->assertOk()
            ->assertJsonPath('otpRequired', true);
    }

    public function test_a_wrong_current_password_changes_nothing(): void
    {
        TrustedDevice::create([
            'user_id' => $this->user->id,
            'token_hash' => hash('sha256', Str::random(48)),
            'expires_at' => now()->addDays(10),
        ]);

        $this->actingAs($this->user)
            ->putJson('/api/v1/profile/password', [
                'current_password' => 'wrong-one',
                'password' => 'brandnew123',
                'password_confirmation' => 'brandnew123',
            ])
            ->assertStatus(422);

        // نه رمز عوض شد نه دستگاهی باطل — شکست نباید عوارض جانبی داشته باشد
        $this->assertSame(1, TrustedDevice::count());
        $this->assertTrue(Hash::check('secret123', $this->user->fresh()->password));
    }

    /* ── پنجره‌ی ۱۰ روزه ────────────────────────────────────────────────── */

    /**
     * «۱۰ روز بدون ورود دوباره» تا امروز فقط ادعا بود.
     *
     * تستِ قبلی می‌سنجید که `expires_at` حدودِ ۱۰ روز بعد است، ولی هیچ‌وقت
     * جلو نمی‌رفت تا ببیند در روزِ نهم واقعاً کار می‌کند و در یازدهم نه.
     */
    public function test_the_device_still_skips_the_code_on_day_nine(): void
    {
        $cookie = $this->issueDeviceCookie();

        $this->travel(9)->days();

        $this->loginWithDevice($cookie)
            ->assertOk()
            ->assertJsonPath('user.phone', '09120000010');
    }

    public function test_the_device_stops_skipping_after_day_eleven(): void
    {
        $cookie = $this->issueDeviceCookie();

        $this->travel(11)->days();

        $this->loginWithDevice($cookie)
            ->assertOk()
            ->assertJsonPath('otpRequired', true);
    }

    /**
     * استفاده‌ی مکرر نباید پنجره را تمدید کند.
     *
     * وگرنه «۱۰ روز» عملاً می‌شد «برای همیشه، تا وقتی هر ده روز یک بار سر
     * بزنی» — که تعهدِ متفاوتی است.
     */
    public function test_using_the_device_does_not_extend_the_window(): void
    {
        $cookie = $this->issueDeviceCookie();
        $expiry = TrustedDevice::first()->expires_at;

        $this->travel(5)->days();
        $this->loginWithDevice($cookie)->assertOk();

        $this->assertEquals($expiry, TrustedDevice::first()->fresh()->expires_at);
    }

    /* ── ورودِ مستقیم به داشبورد ────────────────────────────────────────── */

    /**
     * کاربرِ واردشده‌ای که آدرسِ داشبورد را مستقیم می‌زند نباید به صفحه‌ی
     * ورود پرت شود (فنی-55a).
     */
    public function test_a_signed_in_user_can_open_the_dashboard_url_directly(): void
    {
        $this->actingAs($this->user)->get('/dashboard')->assertOk();
    }

    public function test_a_trusted_device_alone_authenticates_without_a_session(): void
    {
        /*
         * هیچ نشستی وجود ندارد — فقط کوکیِ دستگاه. این همان معنای عملیِ
         * «۱۰ روز بدون ورود دوباره» است: میان‌افزار پیش از رسیدن به کنترلر
         * کاربر را وارد می‌کند.
         */
        $cookie = $this->issueDeviceCookie();

        $this->callWithDevice('GET', '/api/v1/me', [], $cookie)
            ->assertOk()
            ->assertJsonPath('user.phone', '09120000010');
    }

    /* ── وضعیتِ بازدیدکننده روی صفحاتِ عمومی ────────────────────────────── */

    public function test_a_guest_page_carries_no_viewer_tag(): void
    {
        /*
         * نبودنِ تگ برای مهمان یعنی پاسخ بایت‌به‌بایت همان چیزی است که قبلاً
         * بود، پس کش‌پذیریِ صفحه‌ی فرود دست‌نخورده می‌ماند.
         */
        $this->get('/')->assertOk()->assertDontSee('viewer-state');
    }

    public function test_a_signed_in_visitor_gets_the_viewer_tag(): void
    {
        $this->actingAs($this->user)->get('/')->assertOk()->assertSee('viewer-state');
    }

    public function test_the_viewer_tag_leaks_no_personal_data(): void
    {
        /*
         * فقط یک بولین. اگر روزی CDN جلوی صفحه بنشیند، بدترین حالت این است که
         * مهمانی دکمه‌ی «داشبورد» ببیند — نه اینکه نام و شماره‌ی کسی را.
         */
        $html = $this->actingAs($this->user)->get('/')->getContent();

        $this->assertStringNotContainsString('09120000010', $html);
        $this->assertStringNotContainsString('بهرام‌زاده‌فرد', $html);
    }

    /* ── سیاستِ رمز ─────────────────────────────────────────────────────── */

    /**
     * حسابِ مدیرِ مجتمع باید همان سیاستِ رمزِ بقیه را داشته باشد.
     *
     * پیش از R18 اینجا `min:6` بدونِ هیچ قیدی بود — سست‌ترین قاعده‌ی پروژه
     * دقیقاً روی پرقدرت‌ترین حسابِ داخلِ یک مجتمع.
     */
    public function test_a_complex_cannot_be_created_with_a_weak_admin_password(): void
    {
        $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/system/complexes', [
                'name' => 'مجتمع نو',
                'admin_name' => 'مدیر',
                'admin_phone' => '09121112233',
                'admin_password' => '123456',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('admin_password');
    }

    public function test_a_strong_admin_password_is_still_accepted(): void
    {
        $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/system/complexes', [
                'name' => 'مجتمع نو',
                'admin_name' => 'مدیر',
                'admin_phone' => '09121112233',
                'admin_password' => 'goodpass99',
            ])
            ->assertSuccessful();
    }

    /* ── ایمیل: چرا «تایید ایمیل» ساخته نشد ─────────────────────────────── */

    /**
     * ایمیل در این سامانه **عاملِ احراز هویت نیست**.
     *
     * ورود با شماره‌ی موبایل و کدِ پیامکی است، بازیابیِ رمز هم همین‌طور، و
     * هیچ ایمیلی هرگز فرستاده نمی‌شود. پس «تایید ایمیل» هیچ سطحِ حمله‌ای را
     * نمی‌بندد و فقط یک وابستگیِ SMTP اضافه می‌کرد.
     *
     * این تست همان فرض را قفل می‌کند: اگر روزی کسی ایمیل را به مسیرِ ورود یا
     * بازیابی وصل کند، اینجا می‌شکند و آن تصمیم دوباره سنجیده می‌شود — که
     * دقیقاً همان لحظه‌ای است که تایید ایمیل لازم می‌شود.
     */
    public function test_email_is_not_an_authentication_factor(): void
    {
        $authSource = File::get(app_path('Http/Controllers/Api/AuthController.php'));

        $this->assertStringNotContainsString("where('email'", $authSource);
        $this->assertStringNotContainsString('Auth::attempt', $authSource);
    }

    public function test_signing_in_with_an_email_is_not_possible(): void
    {
        $this->user->update(['email' => 'someone@example.com']);

        // اثباتِ رفتاری: ایمیل به‌جای شماره پذیرفته نمی‌شود
        $this->postJson('/api/login', [
            'phone' => 'someone@example.com',
            'password' => 'secret123',
        ])->assertStatus(422);
    }

    /* ── سقفِ روزانه‌ی پیامک ────────────────────────────────────────────── */

    public function test_the_otp_request_limiter_has_a_daily_ceiling(): void
    {
        /*
         * ۳ پیامک در هر ۱۰ دقیقه یعنی ۴۳۲ در شبانه‌روز برای یک شماره، و هر
         * کدام پول واقعی است. پنجره‌ی کوتاه «تندی» را می‌گیرد نه «مجموع» را.
         */
        $source = File::get(app_path('Providers/AppServiceProvider.php'));

        $this->assertStringContainsString('perDay', $source, 'محدودکننده‌ی OTP باید سقف روزانه داشته باشد');
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    /**
     * کوکیِ دستگاه باید **خام** فرستاده شود.
     *
     * این کوکی از رمزنگاریِ کوکی‌ها مستثناست، پس `withCookie` (که مقدارِ
     * رمزشده انتظار دارد) اینجا کار نمی‌کند و بی‌صدا نادیده گرفته می‌شود —
     * که یعنی تست سبز می‌ماند بی‌آنکه چیزی را سنجیده باشد.
     */
    private function callWithDevice(string $method, string $uri, array $body, string $cookie): TestResponse
    {
        return $this->call(
            $method, $uri, [],
            [TrustedDeviceService::COOKIE => $cookie],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $body ? json_encode($body) : null,
        );
    }

    private function loginWithDevice(string $cookie, string $password = 'secret123'): TestResponse
    {
        return $this->callWithDevice('POST', '/api/login', [
            'phone' => '09120000010',
            'password' => $password,
        ], $cookie);
    }

    private function issueDeviceCookie(): string
    {
        $plain = 'd'.Str::random(47);
        $device = TrustedDevice::create([
            'user_id' => $this->user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays(10),
        ]);

        return $device->id.':'.$plain;
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'role' => UserRole::SuperAdmin,
            'complex_id' => $this->complex->id,
            'is_active' => true,
        ]);
    }
}
