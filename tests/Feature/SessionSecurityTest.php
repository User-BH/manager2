<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Support\SystemSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * امنیتِ نشست و کوکی — سنجیده روی **پاسخِ واقعی** (فاز ۳).
 *
 * ─── 🔴 چه چیزی پیش از این فقط ادعا بود ────────────────────────────────────
 * پرچم‌های کوکی تست داشتند، ولی تست `config('session.same_site')` را
 * می‌خواند — یعنی **مقدارِ پیکربندی**، نه کوکی‌ای که واقعاً به مرورگر
 * می‌رود.
 *
 * ⚠️ تفاوتشان جایی معلوم می‌شود که میدل‌وری کوکی را بازنویسی کند، یا
 * پیکربندی درست باشد و لاراول به‌دلیلی اعمالش نکند. آن‌وقت پیکربندی سبز
 * است و کاربر بی‌محافظ.
 *
 * پس اینجا از خودِ سرآیندِ `Set-Cookie` پرسیده می‌شود.
 */
class SessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    /** کوکی‌های واقعیِ یک پاسخ، به‌صورتِ نام ← کوکی. */
    private function cookiesOf(string $uri): array
    {
        $response = $this->get($uri);
        $cookies = [];

        foreach ($response->baseResponse->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie;
        }

        $this->assertNotEmpty($cookies, 'پاسخ هیچ کوکی‌ای نداشت؛ این محافظ پوچ می‌شود.');

        return $cookies;
    }

    /**
     * 🔴 کوکیِ نشست باید `HttpOnly` باشد.
     *
     * ⚠️ بدونِ آن، هر XSS می‌تواند نشست را بخواند و کاربر را جعل کند —
     * بدونِ نیاز به رمز.
     */
    public function test_the_session_cookie_is_http_only_on_the_real_response(): void
    {
        $name = config('session.cookie');
        $cookies = $this->cookiesOf('/api/v1/csrf-token');

        $this->assertArrayHasKey($name, $cookies, "کوکیِ نشست «{$name}» در پاسخ نبود.");

        $session = $cookies[$name];

        $this->assertTrue($session->isHttpOnly(), '🔴 کوکیِ نشست از جاوااسکریپت خواندنی است.');
        $this->assertSame(Cookie::SAMESITE_LAX, $session->getSameSite(), 'کوکیِ نشست SameSite ندارد.');
    }

    /**
     * ⚠️ و کوکیِ CSRF عمداً **خواندنی** است — این تناقض نیست.
     *
     * 🔴 فرانت باید بتواند مقدارش را بخواند و در سرآیندِ `X-XSRF-TOKEN`
     * برگرداند؛ همین رفت‌وبرگشت است که ثابت می‌کند درخواست از خودِ صفحه
     * آمده. اگر `HttpOnly` شود، محافظتِ CSRF از کار می‌افتد.
     *
     * پس این تست عمداً برعکسِ تستِ بالاست، و بودنش لازم است: بدونِ آن،
     * کسی روزی «برای امنیت بیشتر» آن را هم `HttpOnly` می‌کند.
     */
    public function test_the_csrf_cookie_stays_readable_by_design(): void
    {
        $cookies = $this->cookiesOf('/api/v1/csrf-token');

        $this->assertArrayHasKey('XSRF-TOKEN', $cookies);
        $this->assertFalse(
            $cookies['XSRF-TOKEN']->isHttpOnly(),
            '⚠️ کوکیِ CSRF غیرقابلِ‌خواندن شد؛ فرانت دیگر نمی‌تواند توکن را برگرداند.',
        );
    }

    /**
     * 🔴 در محصول، کوکی باید فقط روی HTTPS برود.
     *
     * ⚠️ در تست `secure` خاموش است (چون HTTP است) و همین درست است؛ ولی
     * قاعده‌ی پیکربندی باید بگوید در محصول روشن می‌شود.
     */
    public function test_the_cookie_is_marked_secure_in_production(): void
    {
        /*
         * ⚠️ این تنها ادعای **ساختاری** این کلاس است، و دلیلش صریح:
         *
         * در محیطِ تست ارتباط HTTP است، پس `secure` خاموش است و باید هم
         * باشد — کوکیِ `Secure` روی HTTP اصلاً فرستاده نمی‌شود و همه‌ی
         * تست‌های نشست می‌شکستند.
         *
         * 🔴 پس آنچه سنجیده می‌شود این است که مقدار **از محیط مشتق شود**،
         * نه اینکه روی `false` ثابت بماند. تفاوتش این است: نسخه‌ی ثابت در
         * محصول هم کوکی را روی HTTP می‌فرستد.
         */
        $config = (string) file_get_contents(config_path('session.php'));
        $config = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $config);

        $this->assertMatchesRegularExpression(
            "/'secure'\s*=>\s*env\(/",
            $config,
            '🔴 پرچمِ امنِ کوکی به محیط گره نخورده؛ در محصول هم روی HTTP می‌رود.',
        );

        $this->assertStringContainsString(
            "APP_ENV') === 'production'",
            $config,
            'پیش‌فرضِ پرچمِ امن به محیطِ محصول گره نخورده است.',
        );
    }

    /**
     * 🔴 کدِ یک‌بارمصرف باید واقعاً **یک‌بارمصرف** باشد.
     *
     * ⚠️ بازپخشِ کد یعنی هر کسی که یک بار کد را ببیند — در لاگِ پیامک، در
     * تاریخچه‌ی مرورگر، یا روی شانه‌ی کاربر — می‌تواند بعداً دوباره
     * استفاده‌اش کند.
     */
    public function test_a_used_otp_code_cannot_be_replayed(): void
    {
        SystemSettings::set('otp_disabled', false);

        $user = User::factory()->create([
            'phone' => '09120005566',
            'password' => Hash::make('secret123'),
            'role' => UserRole::Owner->value,
            'is_active' => true,
        ]);

        $first = $this->postJson('/api/v1/login', [
            'phone' => $user->phone, 'password' => 'secret123',
        ])->assertSuccessful()->json('dev_code');

        $this->assertNotEmpty($first, 'کدی برنگشت؛ این محافظ پوچ می‌شود.');

        $this->postJson('/api/v1/login/verify', ['code' => $first])->assertSuccessful();

        $this->postJson('/api/v1/logout');

        /*
         * 🔴 ورودِ **تازه** لازم است، وگرنه تست پوچ می‌شود.
         *
         * ⚠️ `verify` بدونِ «ورودِ در انتظار» به‌هرحال رد می‌شود. اگر
         * همین‌جا کدِ قدیمی را می‌فرستادیم، ۴۲۲ می‌گرفتیم و خیال می‌کردیم
         * بازپخش بسته است — در حالی که فقط نشستی وجود نداشت.
         *
         * پس اول یک ورودِ تازه ساخته می‌شود (که کدِ تازه‌ی خودش را دارد) و
         * بعد کدِ **قدیمی** امتحان می‌شود. حالا اگر رد شود، دلیلش فقط
         * مصرف‌شده بودنِ همان کد است.
         */
        $second = $this->postJson('/api/v1/login', [
            'phone' => $user->phone, 'password' => 'secret123',
        ])->assertSuccessful()->json('dev_code');

        $this->assertNotSame($first, $second, 'کدِ تازه با کدِ قبلی یکی است؛ تست معنا ندارد.');

        $this->postJson('/api/v1/login/verify', ['code' => $first])->assertStatus(422);

        $this->assertGuest();
    }

    /**
     * 🔴 همان کد، دو بار — ادعای واقعیِ یک‌بارمصرف بودن.
     *
     * ─── چرا در سطحِ سرویس و نه HTTP ───────────────────────────────────────
     * ⚠️ خرابکاری نشان داد تستِ HTTPِ بالا `used_at` را نمی‌سنجد: با
     * برداشتنِ `whereNull('used_at')` از سرویس، آن تست **همچنان سبز ماند**.
     *
     * علتش این بود که پس از ورودِ کاملِ موفق، `clear()` همه‌ی کدهای آن
     * شماره را **حذف** می‌کند. پس کدِ قدیمی به‌خاطرِ حذف رد می‌شد، نه
     * به‌خاطرِ مصرف‌شدن — دو محافظِ متفاوت که یکی‌شان بی‌آزمون مانده بود.
     *
     * اینجا `clear()` وسط نمی‌آید، پس تنها چیزی که می‌تواند کد را رد کند
     * همان علامتِ مصرف است.
     */
    public function test_the_same_code_cannot_be_verified_twice(): void
    {
        $otp = app(OtpService::class);
        $phone = '09120009911';

        $issued = $otp->request($phone);
        $code = (string) ($issued['code'] ?? $issued['dev_code'] ?? '');

        $this->assertNotEmpty($code, 'سرویس کدِ خام برنگرداند؛ این محافظ پوچ می‌شود.');

        $this->assertTrue($otp->verify($phone, $code), 'بارِ اول باید بپذیرد.');
        $this->assertFalse($otp->verify($phone, $code), '🔴 همان کد بارِ دوم هم پذیرفته شد.');
    }

    /** ⚠️ و کدِ تازه باید کار کند، وگرنه محافظ زیادی سفت است. */
    public function test_a_fresh_code_still_works(): void
    {
        SystemSettings::set('otp_disabled', false);

        $user = User::factory()->create([
            'phone' => '09120007788',
            'password' => Hash::make('secret123'),
            'role' => UserRole::Owner->value,
            'is_active' => true,
        ]);

        $code = $this->postJson('/api/v1/login', [
            'phone' => $user->phone, 'password' => 'secret123',
        ])->json('dev_code');

        $this->postJson('/api/v1/login/verify', ['code' => $code])->assertSuccessful();

        $this->assertAuthenticated();
    }

    /**
     * 🔴 سقفِ نرخ باید روی **IP** هم باشد، نه فقط شماره.
     *
     * ⚠️ سقفِ per-phone به‌تنهایی جلوی credential stuffing را نمی‌گیرد:
     * مهاجم روی هزار شمارهٔ مختلف هرکدام دو بار تلاش می‌کند و هرگز به
     * سقفِ شماره نمی‌خورد.
     *
     * ⚠️ تست‌های موجود فقط جداییِ per-phone را می‌سنجیدند.
     */
    public function test_many_phones_from_one_ip_hit_the_ip_ceiling(): void
    {
        RateLimiter::clear('login');

        $blocked = false;

        // سقفِ IP بیست در دقیقه است؛ هر شماره تازه، پس سقفِ شماره فعال نمی‌شود
        for ($i = 0; $i < 25; $i++) {
            $response = $this->post('/api/v1/login', [
                'phone' => '0912000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'password' => 'wrong-password',
            ]);

            if ($response->getStatusCode() === 429) {
                $blocked = true;
                break;
            }
        }

        $this->assertTrue(
            $blocked,
            '🔴 بیست‌وپنج تلاش روی شماره‌های مختلف از یک IP بدونِ سقف رد شد.',
        );
    }
}
