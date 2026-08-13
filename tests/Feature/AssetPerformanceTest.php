<?php

namespace Tests\Feature;

use App\Support\ResourceHints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قراردادهای کاراییِ دارایی‌ها (R36).
 *
 * ─── چرا این‌ها تست دارند ───────────────────────────────────────────────────
 * افتِ کارایی **هیچ خطایی نمی‌دهد**. یک `import` ایستا به‌جای پویا، یا یک
 * `<img>` بدونِ `width`، صفحه را همچنان درست نشان می‌دهد — فقط کندتر و با
 * پرش. تنها راهِ نگه‌داشتنشان، سنجیدنِ خودِ قرارداد است.
 */
class AssetPerformanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int,array{file:string,line:int,tag:string,context:string}>
     */
    private function imageTags(): array
    {
        $found = [];

        foreach ($this->sourceFiles() as $path) {
            $source = (string) file_get_contents($path);

            preg_match_all('/<img\b[^>]*>/s', $source, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [$tag, $offset]) {
                $found[] = [
                    'file' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $path),
                    'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                    'tag' => $tag,
                    // چند خطِ بالای تگ، تا نشانه‌گذاری‌های صریح دیده شوند
                    'context' => substr($source, max(0, $offset - 700), 700),
                ];
            }
        }

        return $found;
    }

    /**
     * @return array<int,string>
     */
    private function sourceFiles(): array
    {
        $files = [];

        foreach ([resource_path('js'), resource_path('views')] as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($iterator as $file) {
                if ($file->isFile() && preg_match('/\.(tsx|php)$/', $file->getFilename())) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * ⚠️ هر `<img>` باید ابعاد داشته باشد.
     *
     * بدونِ `width`/`height`، مرورگر تا رسیدنِ خودِ فایل نمی‌داند چقدر جا
     * رزرو کند و لحظه‌ی رسیدن، کلِ صفحه می‌پرد (CLS). این پرش را کاربر
     * حس می‌کند ولی هیچ لاگی ثبت نمی‌شود.
     *
     * استثنا: نشانِ اینماد. کدش را کارفرما صریحاً «دست نزن» گفته و خودش
     * هم از قبل width/height دارد.
     */
    public function test_every_image_reserves_its_space(): void
    {
        foreach ($this->imageTags() as $image) {
            if (str_contains($image['tag'], 'trustseal') || str_contains($image['tag'], 'enamad')) {
                continue;
            }

            /*
             * ⚠️ استثنای صریح، نه استثنای خاموش.
             *
             * ابعادِ تصویری که کاربر آپلود کرده در زمانِ ساخت معلوم نیست، پس
             * `width`/`height` قابلِ نوشتن نیست و فضا باید از ظرف بیاید. این
             * حالت **مجاز** است، ولی فقط اگر نویسنده صریحاً `cls-safe` را
             * کنارش نوشته باشد — تا «نمی‌دانستم» از «سنجیدم و این‌طور شد»
             * جدا بماند.
             */
            if (str_contains($image['context'], 'cls-safe')) {
                continue;
            }

            /*
             * ⚠️ صفتِ `width` تنها راهِ رزروِ فضا نیست: `style={{ width }}` با
             * مقدارِ پیکسلی هم دقیقاً همان کار را می‌کند — و در پازلِ ورود
             * همان استفاده شده. قاعده‌ی اولم فقط شکلِ صفتی را می‌پذیرفت و
             * یک کدِ کاملاً درست را مردود اعلام کرد.
             */
            foreach (['width', 'height'] as $attribute) {
                $this->assertMatchesRegularExpression(
                    '/\b'.$attribute.'\s*[={:]/',
                    $image['tag'],
                    "{$image['file']}:{$image['line']} — تصویر «{$attribute}» ندارد، پس چیدمان هنگامِ رسیدنش می‌پرد.",
                );
            }
        }
    }

    /**
     * ⚠️ هر تصویر باید یا `loading` داشته باشد یا `fetchPriority`.
     *
     * دو حالتِ درست وجود دارد و هر دو باید **صریح** باشند:
     *  • تصویرِ پایینِ تا ⟶ `loading="lazy"`
     *  • تصویرِ LCP ⟶ `fetchPriority="high"` (و عمداً بدونِ lazy)
     *
     * چیزی که هیچ‌کدام را ندارد، تصمیمی نگرفته — یعنی پیش‌فرضِ مرورگر را
     * گرفته بی‌آنکه کسی سنجیده باشد.
     */
    public function test_every_image_declares_its_loading_intent(): void
    {
        foreach ($this->imageTags() as $image) {
            if (str_contains($image['tag'], 'trustseal') || str_contains($image['tag'], 'enamad')) {
                continue;
            }

            $this->assertTrue(
                str_contains($image['tag'], 'loading') || str_contains($image['tag'], 'fetchPriority'),
                "{$image['file']}:{$image['line']} — تصویر نه lazy است نه اولویت‌دار؛ یعنی تصمیمی گرفته نشده.",
            );
        }
    }

    /**
     * ⚠️ Recharts نباید ایستا وارد شود.
     *
     * پیش از R36، `DashboardPage` هر دو چارت را ایستا import می‌کرد و کلِ
     * Recharts داخلِ چانکِ داشبورد می‌نشست: ‎۴۰۶KB خام / ‎۱۱۶KB فشرده،
     * بزرگ‌ترین چانکِ پروژه و اولین چیزی که کاربر پس از ورود می‌گیرد.
     */
    public function test_charts_are_never_imported_statically_by_a_page(): void
    {
        foreach ($this->sourceFiles() as $path) {
            if (! str_ends_with($path, '.tsx')) {
                continue;
            }

            $source = (string) file_get_contents($path);
            $name = basename($path);

            // خودِ کامپوننتِ چارت طبعاً recharts را وارد می‌کند
            if (in_array($name, ['TrendChart.tsx', 'PaymentStatusChart.tsx'], true)) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                "/^import[^;]*from '(recharts|swiper|sweetalert2)'/m",
                $source,
                "{$name} یکی از کتابخانه‌های سنگین را **ایستا** وارد می‌کند؛ باید پویا باشد.",
            );
        }
    }

    /**
     * ⚠️ `preconnect` فقط برای مبدأیی که واقعاً استفاده می‌شود.
     *
     * اتصالِ زودهنگام به دامنه‌ای که هیچ درخواستی به آن نمی‌رود، یک دستِ
     * کاملِ DNS+TCP+TLS باز می‌کند و سهمیه‌ی اتصالِ هم‌زمانِ مرورگر را از
     * منابعِ واقعیِ صفحه می‌گیرد — یعنی «بهینه‌سازی»‌ای که کند می‌کند.
     */
    public function test_no_preconnect_is_emitted_for_unconfigured_services(): void
    {
        // هیچ شناسه‌ی تحلیلی‌ای هنوز تنظیم نشده
        $this->assertSame([], ResourceHints::origins());

        $this->get('/')->assertDontSee('rel="preconnect"', false);
    }

    public function test_preconnect_appears_once_a_service_is_configured(): void
    {
        config(['observability.ga4.measurement_id' => 'G-TEST123']);

        $origins = ResourceHints::origins();

        $this->assertContains('https://www.googletagmanager.com', $origins);
    }

    /**
     * فونتِ متنِ اصلی باید preload شود، و فقط همان یکی.
     *
     * preloadِ هر چهار وزن ضدِ خودش عمل می‌کند: ‎۲۰۰KB با اولویتِ بالا با
     * خودِ CSS و JS بر سر پهنای باند رقابت می‌کند.
     */
    public function test_only_the_body_font_is_preloaded(): void
    {
        $html = $this->get('/')->getContent();

        preg_match_all('/<link[^>]+rel="preload"[^>]+as="font"[^>]*>/', (string) $html, $matches);

        $this->assertCount(1, $matches[0], 'دقیقاً یک فونت باید preload شود.');
        $this->assertStringContainsString('Vazirmatn-Regular', $matches[0][0]);

        /*
         * ⚠️ `crossorigin` حتی برای فایلِ هم‌مبدأ اجباری است: بدونش مرورگر
         * فونت را **دو بار** می‌گیرد، چون preload و درخواستِ واقعیِ فونت دو
         * حالتِ CORS متفاوت دارند و کشِ یکی به کارِ دیگری نمی‌آید.
         */
        $this->assertStringContainsString('crossorigin', $matches[0][0]);
    }

    /**
     * تصویرِ LCPِ صفحه‌ی فرود باید preload شود — و فقط همان صفحه.
     */
    public function test_the_landing_hero_is_preloaded_but_other_pages_are_not(): void
    {
        $this->get('/')->assertSee('as="image"', false);

        foreach (['/demo', '/support', '/auth'] as $url) {
            $this->get($url)->assertDontSee('as="image"', false);
        }
    }
}
