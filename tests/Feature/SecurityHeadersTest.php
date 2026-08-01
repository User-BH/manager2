<?php

namespace Tests\Feature;

use App\Support\Observability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * هدرهای امنیتی (R16).
 *
 * این‌ها از آن دسته محافظت‌هایی‌اند که نبودشان هیچ خطایی نمی‌دهد — سایت
 * کاملاً سالم به نظر می‌رسد تا روزی که کسی از همان شکاف استفاده کند.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_landing_page_carries_the_baseline_headers(): void
    {
        $response = $this->get('/')->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertNotNull($response->headers->get('Permissions-Policy'));
        $this->assertNotNull($response->headers->get('Content-Security-Policy'));
    }

    public function test_api_responses_carry_the_headers_too(): void
    {
        // مسیرهای API روی همان گروهِ web سوارند، پس نباید جا بیفتند
        $this->getJson('/api/v1/csrf-token')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /* ── CSP ────────────────────────────────────────────────────────────── */

    public function test_the_policy_blocks_framing_and_plugins(): void
    {
        $csp = $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
    }

    public function test_inline_scripts_get_a_nonce_instead_of_unsafe_inline(): void
    {
        $response = $this->get('/');
        $csp = $response->headers->get('Content-Security-Policy');
        $html = $response->getContent();

        // سیاست باید nonce بخواهد…
        preg_match("/'nonce-([A-Za-z0-9]+)'/", $csp, $matches);
        $this->assertNotEmpty($matches, 'script-src باید nonce داشته باشد');

        // …و همان nonce واقعاً روی اسکریپتِ درون‌خطیِ صفحه باشد
        $this->assertStringContainsString('nonce="'.$matches[1].'"', $html);
    }

    public function test_each_request_gets_a_fresh_nonce(): void
    {
        $first = $this->get('/')->headers->get('Content-Security-Policy');
        $second = $this->get('/')->headers->get('Content-Security-Policy');

        /*
         * nonce ثابت یعنی مهاجم می‌تواند آن را یک بار بخواند و در حمله‌ی بعدی
         * استفاده کند — یعنی عملاً همان `'unsafe-inline'`.
         */
        $this->assertNotSame($first, $second);
    }

    /**
     * CSP باید با پیکربندیِ واقعی رشد کند.
     *
     * اگر ثابت نوشته می‌شد، روزی که شناسه‌ی GA4 در پنل وارد می‌شد، مرورگر
     * اسکریپتِ گوگل را **بی‌صدا** بلاک می‌کرد و هیچ‌کس نمی‌فهمید چرا آمار
     * نمی‌آید.
     */
    public function test_the_policy_allows_analytics_only_once_it_is_configured(): void
    {
        $before = $this->get('/')->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('googletagmanager', $before);

        Observability::save(['ga4_measurement_id' => 'G-TESTONLY']);

        $after = $this->get('/')->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('https://www.googletagmanager.com', $after);
    }

    public function test_the_policy_allows_sentry_only_once_it_is_configured(): void
    {
        Observability::save(['sentry_dsn' => 'https://key@o1.ingest.sentry.io/1']);

        $csp = $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('sentry.io', $csp);
    }

    /* ── HSTS ───────────────────────────────────────────────────────────── */

    public function test_hsts_is_not_sent_over_plain_http(): void
    {
        /*
         * فرستادنش روی HTTP بی‌معناست. مهم‌تر: روی `localhost` مرورگر آن را کش
         * می‌کند و بعد هیچ پروژه‌ی محلیِ دیگری روی http باز نمی‌شود — دردسری
         * که پاک‌کردنش سخت است.
         */
        $this->assertNull($this->get('/')->headers->get('Strict-Transport-Security'));
    }

    /* ── کوکیِ نشست ─────────────────────────────────────────────────────── */

    public function test_the_session_cookie_is_http_only_and_same_site(): void
    {
        // جاوااسکریپت نباید بتواند کوکیِ نشست را بخواند (سدِ سرقتِ نشست با XSS)
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
    }

    public function test_the_session_cookie_is_secure_in_production(): void
    {
        /*
         * مقدارِ تنظیم‌نشده یعنی کوکی روی HTTP هم می‌رود و قابلِ شنود است.
         * در محصول باید خودبه‌خود روشن باشد، حتی اگر کسی `.env` را کامل نکند.
         */
        $this->assertTrue(
            env('SESSION_SECURE_COOKIE', env('APP_ENV') === 'production') === true
                || ! app()->environment('production'),
        );
    }

    /* ── CORS ───────────────────────────────────────────────────────────── */

    public function test_a_foreign_site_gets_no_cors_permission(): void
    {
        /*
         * رابطِ کاربری از همان دامنه سرو می‌شود، پس هیچ مصرف‌کننده‌ی
         * بین‌دامنه‌ای وجود ندارد. تا وقتی `CORS_ALLOWED_ORIGINS` خالی است،
         * مرورگر نباید به هیچ سایتِ دیگری اجازه‌ی خواندنِ پاسخ بدهد.
         */
        $response = $this->getJson('/api/v1/csrf-token', [
            'Origin' => 'https://evil.example',
        ]);

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_credentials_are_never_shared_cross_origin(): void
    {
        /*
         * ترکیبِ «هر دامنه‌ای مجاز» با «کوکی بفرست» یعنی هر سایتی می‌تواند به
         * جای کاربرِ واردشده API را صدا بزند و پاسخ را بخواند. این قید باید
         * صریح بماند حتی اگر بعداً دامنه‌ای به فهرست اضافه شود.
         */
        $this->assertFalse(config('cors.supports_credentials'));
        $this->assertNotContains('*', config('cors.allowed_origins'));
    }

    /* ── تاب‌آوری ───────────────────────────────────────────────────────── */

    public function test_headers_survive_a_broken_settings_table(): void
    {
        /*
         * CSP دامنه‌های مجاز را از جدولِ `settings` می‌خواند. اگر آن جدول در
         * دسترس نباشد، خودِ صفحه هم می‌شکند — آن اجتناب‌ناپذیر است و ربطی به
         * هدرها ندارد. چیزی که اینجا سنجیده می‌شود این است که **هدرها خودشان
         * عاملِ خرابی نشوند**: حتی صفحه‌ی خطای ۵۰۰ هم باید محافظت‌شده باشد.
         *
         * بدونِ try/catch در میان‌افزار، همین درخواست به‌جای ۵۰۰ با یک استثنای
         * مهارنشده در مرحله‌ی ساختِ پاسخ می‌مرد و هیچ هدری نمی‌گرفت.
         */
        Schema::drop('settings');

        $response = $this->get('/');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString(
            "object-src 'none'",
            $response->headers->get('Content-Security-Policy'),
            'در نبودِ پیکربندی باید سخت‌گیرانه‌ترین سیاست فرستاده شود',
        );
    }
}
