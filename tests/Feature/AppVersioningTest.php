<?php

namespace Tests\Feature;

use App\Http\Middleware\AppBuildHeader;
use App\Support\AppBuild;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * جلوگیری از Mixed App Versions (R61).
 *
 * ─── 🔴 مسئله ──────────────────────────────────────────────────────────────
 * ⚠️ کاربری که تب را باز گذاشته، جاوااسکریپتِ دیروز را در حافظه دارد. ما
 * امروز استقرار می‌دهیم. از آن لحظه، آن تب با APIی حرف می‌زند که کدش عوض
 * شده — و هیچ‌کس خبر ندارد.
 *
 * خرابی‌اش معمولاً «خطا» نیست، **رفتارِ اشتباه** است: فیلدی که دیگر نیست
 * `undefined` می‌شود، ستونی خالی می‌ماند، دکمه‌ای کاری نمی‌کند. چون خطایی
 * ثبت نمی‌شود، تنها نشانه‌اش شکایتِ کاربر است.
 *
 * ─── سازوکار ───────────────────────────────────────────────────────────────
 * HTML شناسه‌ی ساخت را در `<meta name="app-build">` می‌آورد؛ هر پاسخِ API
 * همان را در هدرِ `X-App-Build` می‌گذارد. فرقشان یعنی استقراری انجام شده.
 */
class AppVersioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        AppBuild::forget();
    }

    // ─────────────────────────────────────────────────────────────────────
    //  شناسه‌ی ساخت
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 🔴 باید **دقیقاً** وقتی عوض شود که خروجیِ ساخت عوض شده.
     *
     * ⚠️ همین ادعا دلیلِ انتخابِ هشِ `manifest.json` است و نه هشِ کامیت:
     * هشِ کامیت با هر تغییرِ بک‌اند هم عوض می‌شود و تبِ سالمِ کاربر را
     * بی‌دلیل به «نسخه‌ی تازه» هشدار می‌دهد — و هشدارِ کاذب سریع‌تر از هر
     * چیزی اعتماد را از بین می‌برد.
     */
    public function test_the_build_id_tracks_the_frontend_manifest(): void
    {
        $manifest = public_path('build/manifest.json');
        $existed = File::exists($manifest);
        $original = $existed ? File::get($manifest) : null;

        File::ensureDirectoryExists(dirname($manifest));

        try {
            File::put($manifest, '{"a":1}');
            $first = AppBuild::compute();

            File::put($manifest, '{"a":1}');
            $this->assertSame($first, AppBuild::compute(), 'محتوای یکسان شناسه‌ی متفاوت داد.');

            File::put($manifest, '{"a":2}');
            $this->assertNotSame($first, AppBuild::compute(), 'ساختِ تازه شناسه‌ی تازه نداد.');
        } finally {
            $existed ? File::put($manifest, (string) $original) : File::delete($manifest);
            AppBuild::forget();
        }
    }

    /**
     * ⚠️ نبودِ manifest نباید هشدارِ کاذب بسازد.
     *
     * در محیطِ توسعه (سرورِ Vite) یا پیش از اولین ساخت این فایل نیست.
     * مقدارِ ثابتِ `dev` هرگز با خودش فرق نمی‌کند، پس نوارِ «نسخه‌ی تازه»
     * بی‌دلیل بالا نمی‌آید.
     */
    public function test_a_missing_manifest_yields_a_stable_placeholder(): void
    {
        $manifest = public_path('build/manifest.json');
        $existed = File::exists($manifest);
        $original = $existed ? File::get($manifest) : null;

        try {
            File::delete($manifest);

            $this->assertSame(AppBuild::DEV, AppBuild::compute());
        } finally {
            if ($existed) {
                File::put($manifest, (string) $original);
            }

            AppBuild::forget();
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  دو سرِ قرارداد
    // ─────────────────────────────────────────────────────────────────────

    /** هر پاسخِ API باید نسخه‌ی فرانت را اعلام کند. */
    public function test_api_responses_announce_the_build(): void
    {
        $response = $this->getJson('/api/v1/features');

        $this->assertSame(
            AppBuild::current(),
            $response->headers->get(AppBuildHeader::HEADER),
            'پاسخِ API شناسه‌ی ساخت را اعلام نمی‌کند.',
        );
    }

    /**
     * ⚠️ حتی روی پاسخِ **خطا**.
     *
     * وقتی نسخه‌ی فرانت و API از هم جدا می‌افتند، اولین نشانه معمولاً
     * خطاست — ۴۲۲ روی فیلدی که دیگر نیست، ۴۰۴ روی مسیری که عوض شده. اگر
     * هدر فقط روی پاسخِ موفق بود، دقیقاً همان موردی که این سازوکار برایش
     * هست از تور رد می‌شد.
     */
    public function test_error_responses_announce_the_build_too(): void
    {
        $response = $this->getJson('/api/v1/my-bills');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertNotNull(
            $response->headers->get(AppBuildHeader::HEADER),
            'پاسخِ خطا شناسه‌ی ساخت ندارد.',
        );
    }

    /** ⚠️ سطحِ هدر عمداً کوچک می‌ماند: فقط API. */
    public function test_ordinary_pages_do_not_carry_the_header(): void
    {
        $this->assertNull($this->get('/')->headers->get(AppBuildHeader::HEADER));
    }

    /**
     * 🔴 سرِ دیگرِ قرارداد.
     *
     * بدونِ متاتگ، کلاینت چیزی برای مقایسه ندارد و هدر بی‌مصرف است —
     * یعنی کلِ سازوکار خاموش می‌شود بی‌آنکه چیزی قرمز شود.
     */
    public function test_both_layouts_carry_the_build_meta(): void
    {
        foreach (['resources/views/spa.blade.php', 'resources/views/layouts/public.blade.php'] as $view) {
            $this->assertStringContainsString(
                'name="app-build"',
                (string) File::get(base_path($view)),
                "«{$view}» شناسه‌ی ساخت را در HTML نمی‌گذارد.",
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  بازگشتِ خودِ service worker
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 🔴 راهِ بازگشت باید وجود داشته باشد و **روشن** باشد.
     *
     * ⚠️ استقرارِ خرابِ بک‌اند را با یک revert برمی‌گردانیم. ولی service
     * workerِ خراب روی دستگاهِ کاربر نصب شده و هیچ استقراری به‌تنهایی
     * پاکش نمی‌کند. پرچمِ `pwa` تنها اهرمِ از راه دور است.
     */
    public function test_the_service_worker_kill_switch_exists_and_defaults_on(): void
    {
        $flags = (array) config('features.flags');

        $this->assertArrayHasKey('pwa', $flags, 'کلیدِ خاموشیِ service worker وجود ندارد.');
        $this->assertTrue($flags['pwa']['default'], 'PWA به‌طور پیش‌فرض خاموش است.');

        $this->getJson('/api/v1/features')->assertOk()->assertJsonPath('data.pwa', true);
    }

    /**
     * ⚠️ رویه‌ی بازگشت باید **مکتوب** باشد.
     *
     * پرچمی که کسی نداند کِی و چرا باید خاموشش کند، روزِ حادثه خاموش
     * نمی‌شود.
     */
    public function test_the_rollback_procedure_is_written_down(): void
    {
        $document = (string) File::get(base_path('docs/API_COMPATIBILITY.md'));

        foreach (['حالتِ برنامه (PWA)', 'X-App-Build', 'Mixed App Versions', 'unregister'] as $needle) {
            $this->assertStringContainsString($needle, $document, "سند «{$needle}» را توضیح نمی‌دهد.");
        }
    }
}
