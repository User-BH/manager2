<?php

namespace Tests\Feature;

use App\Console\Commands\PerformanceBaseline;
use App\Enums\UserRole;
use App\Jobs\BaseJob;
use App\Models\Announcement;
use App\Models\Complex;
use App\Models\User;
use App\Models\WebVital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

/**
 * بودجه‌ی کارایی و پایه‌ی اندازه‌گیری (R65).
 *
 * ⚠️ قاعده‌ی این مرحله: **هیچ بهینه‌سازی بدونِ اندازه‌گیری.** پس اینجا
 * چیزی «سریع‌تر» نمی‌شود؛ اینجا سقف گذاشته می‌شود تا آنچه امروز اندازه
 * گرفته شده، فردا بی‌سروصدا بدتر نشود.
 */
class PerformanceBaselineTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────────────────────────
    //  بودجه‌ی تصویر
    // ─────────────────────────────────────────────────────────────────────

    /**
     * تصویرهایی که سقفِ تکی شاملشان نمی‌شود — با دلیل.
     *
     * ⚠️ همان الگوی همیشگیِ این پروژه: معافیت باید **نوشته** شود، وگرنه
     * اولین فایلِ سنگینِ بعدی هم بی‌صدا معاف می‌شود.
     *
     * @var array<string, string>
     */
    private const IMAGE_EXEMPT = [
        'og-cover.png' => 'کارتِ اشتراک‌گذاری؛ فقط خزنده‌ی شبکه‌ی اجتماعی می‌گیردش، نه مرورگرِ کاربر.',
        'enamad.png' => 'نشانِ اعتماد؛ کدش نباید دست بخورد و حجمش هم ناچیز است.',
    ];

    /**
     * 🔴 سقفِ **هر** تصویر، مهم‌تر از سقفِ مجموع است.
     *
     * چیزی که واقعاً اتفاق می‌افتد این است که کسی یک PNGِ مستقیم از دوربین
     * را کنارِ بقیه می‌گذارد. سقفِ مجموع آن را ماه‌ها بعد نشان می‌دهد؛ سقفِ
     * تکی همان روز.
     */
    public function test_no_single_image_exceeds_its_budget(): void
    {
        $budget = (int) config('performance.assets.max_image_kb') * 1024;
        $oversized = [];

        foreach ($this->images() as $path => $bytes) {
            if (isset(self::IMAGE_EXEMPT[basename($path)])) {
                continue;
            }

            if ($bytes > $budget) {
                $oversized[] = basename($path).' — '.round($bytes / 1024).'KB';
            }
        }

        $this->assertSame(
            [],
            $oversized,
            'این تصویرها از سقفِ '.config('performance.assets.max_image_kb')."KB گذشته‌اند:\n  "
            .implode("\n  ", $oversized)."\n"
            .'یا فشرده‌شان کنید، یا با دلیل در `IMAGE_EXEMPT` ثبتشان کنید.',
        );
    }

    public function test_the_total_image_weight_stays_within_budget(): void
    {
        $total = 0;

        foreach ($this->images() as $path => $bytes) {
            if (isset(self::IMAGE_EXEMPT[basename($path)])) {
                continue;
            }

            $total += $bytes;
        }

        $budget = (int) config('performance.assets.total_images_kb') * 1024;

        $this->assertLessThanOrEqual(
            $budget,
            $total,
            sprintf(
                'مجموعِ تصویرها %dKB است و از سقفِ %dKB گذشته.',
                (int) round($total / 1024),
                (int) round($budget / 1024),
            ),
        );
    }

    /**
     * ⚠️ معافیت‌ها نباید کهنه بمانند.
     *
     * فایلی که دیگر وجود ندارد و همچنان در سیاهه است، یعنی سیاهه دارد
     * تبدیل به فهرستی می‌شود که کسی نمی‌خواندش.
     */
    public function test_every_image_exemption_still_points_at_a_real_file(): void
    {
        $names = array_map('basename', array_keys($this->images()));

        foreach (array_keys(self::IMAGE_EXEMPT) as $exempt) {
            $this->assertContains($exempt, $names, "معافیتِ «{$exempt}» به فایلی اشاره می‌کند که نیست.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  بودجه‌ی فونت
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ فونت گران‌ترین دارایی‌ای است که کسی حسابش نمی‌کند.
     *
     * چهار وزنِ وزیرمتن روی هم بیشتر از کلِ CSS این پروژه‌اند. سقفِ تکی
     * جلوی افزودنِ وزنِ پنجم را نمی‌گیرد؛ سقفِ مجموع می‌گیرد.
     */
    public function test_fonts_stay_within_budget(): void
    {
        $files = glob(resource_path('fonts').DIRECTORY_SEPARATOR.'*.woff2') ?: [];

        $this->assertNotEmpty($files, 'هیچ فونتی پیدا نشد؛ الگوی مسیر خراب است.');

        $perFile = (int) config('performance.assets.max_font_kb') * 1024;
        $total = 0;

        foreach ($files as $file) {
            $bytes = (int) filesize($file);
            $total += $bytes;

            $this->assertLessThanOrEqual(
                $perFile,
                $bytes,
                basename($file).' — '.round($bytes / 1024).'KB از سقفِ تکی گذشته.',
            );
        }

        $this->assertLessThanOrEqual(
            (int) config('performance.assets.total_fonts_kb') * 1024,
            $total,
            'مجموعِ فونت‌ها '.(int) round($total / 1024).'KB است.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  آستانه‌ها: یک تعریف، دو جا
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 🔴 آستانه‌های Core Web Vitals دو نسخه دارند و باید یکی بمانند.
     *
     * مرورگر با نسخه‌ی TypeScript رتبه‌بندی می‌کند و ردیف را ذخیره؛ گزارشِ
     * سرور با نسخه‌ی PHP قضاوت می‌کند. جدا افتادنشان یعنی یک ردیف در
     * دیتابیس `good` باشد و در گزارش `poor` — و آن‌وقت هیچ‌کدام قابلِ اتکا
     * نیست.
     */
    public function test_the_vitals_thresholds_agree_with_the_browser(): void
    {
        $source = (string) file_get_contents(
            resource_path('js/shared/lib/observability/webVitals.ts')
        );

        $this->assertSame(
            1,
            preg_match('/const GOOD = \{([^}]+)\}/', $source, $match),
            'شیءِ GOOD در webVitals.ts پیدا نشد؛ الگو کهنه شده.',
        );

        preg_match_all('/(\w+):\s*([\d.]+)/', $match[1], $pairs, PREG_SET_ORDER);

        $browser = [];

        foreach ($pairs as [, $name, $value]) {
            $browser[$name] = (float) $value;
        }

        $server = array_map('floatval', (array) config('performance.vitals.good'));

        ksort($browser);
        ksort($server);

        $this->assertSame($server, $browser, 'آستانه‌های مرورگر و سرور از هم جدا افتاده‌اند.');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  کارِ پس‌زمینه: Retry / Backoff / Timeout
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 🔴 مهم‌ترین یافته‌ی اندازه‌گیریِ R65.
     *
     * `retry_after` یعنی «اگر کاری این‌قدر طول کشید، فرض کن کارگرش مرده و
     * دوباره تحویلش بده». اگر از `$timeout` کارها کوچک‌تر باشد، صف کارِ
     * **در حالِ اجرا** را به کارگرِ دوم هم می‌دهد و همان سند دو بار ساخته
     * می‌شود.
     *
     * پیش از R65: `retry_after = 90` در برابرِ `BuildBillsBundleJob` با
     * `$timeout = 900`.
     */
    public function test_the_queue_never_retries_a_job_that_is_still_running(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');

        /*
         * 🔴 حاشیهٔ صریح، نه صرفاً «بزرگ‌تر» (فاز ۷).
         *
         * ─── چرا `assertGreaterThan` کافی نبود ─────────────────────────────
         * ⚠️ نسخهٔ قبلی فقط می‌سنجید که `retry_after` از `timeout` بزرگ‌تر
         * باشد. یعنی `retry_after = 901` در برابرِ کارِ ۹۰۰ ثانیه‌ای هم سبز
         * می‌ماند — **یک ثانیه** حاشیه، که عملاً همان باگ است.
         *
         * فاز ۰ همین ضعف را ثبت کرد و به اینجا سپرد.
         *
         * ─── این شصت ثانیه چه چیزی را می‌پوشاند ────────────────────────────
         * سه چیز که هیچ‌کدام «زمانِ اجرای کار» نیستند ولی به همان پنجره
         * اضافه می‌شوند:
         *
         *   • فاصلهٔ نظرسنجیِ کارگر تا برداشتنِ کار
         *   • زمانِ کشتنِ کار پس از رسیدن به `timeout`
         *   • اختلافِ ساعتِ سرورِ برنامه و دیتابیس
         *
         * ⚠️ هیچ‌کدام بزرگ نیستند، ولی جمعشان می‌تواند از چند ثانیه بگذرد —
         * و نتیجه‌اش **دو اجرای هم‌زمانِ یک کار** است، که برای ساختِ سند
         * یعنی دو فایل و برای هر کارِ مالی یعنی دو بار.
         */
        $margin = 60;

        foreach ($this->queueableJobs() as $class) {
            $timeout = (int) $this->defaults($class)['timeout'];

            $this->assertGreaterThanOrEqual(
                $timeout + $margin,
                $retryAfter,
                "{$class} تا {$timeout} ثانیه اجرا می‌شود و صف پس از {$retryAfter} ثانیه "
                ."دوباره تحویلش می‌دهد. دستِ‌کم {$margin} ثانیه حاشیه لازم است — "
                .'وگرنه یک تأخیرِ کوچک یعنی دو اجرای هم‌زمانِ یک کار.',
            );
        }
    }

    /**
     * ⚠️ هر کارِ صف باید از `BaseJob` بیاید.
     *
     * بدونِ آن، `tries`/`backoff`/`timeout` جا می‌افتند و لاراول پیش‌فرضِ
     * خودش را می‌گذارد: تلاشِ **بی‌نهایت** و بدونِ مهلت. هیچ‌کدام خطا
     * نمی‌دهند؛ فقط صف بند می‌آید.
     */
    public function test_every_queued_class_inherits_the_shared_limits(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            $source = (string) file_get_contents($file);

            if (! str_contains($source, 'implements ShouldQueue')) {
                continue;
            }

            if (! str_contains($source, 'abstract class BaseJob')) {
                $offenders[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen(base_path()) + 1));
            }
        }

        $this->assertSame([], $offenders, 'این کلاس‌ها مستقیم ShouldQueue شده‌اند به‌جای ارث‌بری از BaseJob.');
    }

    /** سه عددِ مشترک باید واقعاً مقدار داشته باشند، نه اینکه فقط اعلام شده باشند. */
    public function test_the_shared_job_limits_are_bounded(): void
    {
        $job = $this->defaults(BaseJob::class);

        $this->assertGreaterThan(0, $job['tries'], 'تلاشِ نامحدود یعنی کارِ خراب برای همیشه صف را مشغول کند.');
        $this->assertLessThanOrEqual(5, $job['tries']);
        $this->assertNotEmpty($job['backoff'], 'بدونِ backoff، سه تلاش پشتِ سرِ هم به سرویسِ زیرِ فشار می‌کوبد.');
        $this->assertGreaterThan(0, $job['timeout'], 'کارِ بی‌مهلت کارگر را برای همیشه اشغال می‌کند.');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  پرترافیک‌ترین مسیرِ احراز هویت‌شده
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 🔴 `GET /notifications` را **هر کاربرِ واردشده هر شصت ثانیه** می‌زند.
     *
     * پس هزینه‌اش در تعدادِ کاربرانِ آنلاین ضرب می‌شود؛ هیچ مسیرِ دیگری در
     * این سامانه چنین ضریبی ندارد.
     *
     * اندازه‌گیریِ R65 دو اتلاف پیدا کرد: شمارشِ پیام‌رسان **دو بار** اجرا
     * می‌شد، و `readIds` با فهرستِ خالی هم به دیتابیس می‌رفت (`and 0 = 1`).
     */
    public function test_the_polled_endpoint_stays_within_its_query_budget(): void
    {
        [$user] = $this->tenant();

        $profile = $this->countQueries(
            fn () => $this->actingAs($user)->getJson('/api/v1/notifications')->assertOk()
        );

        /*
         * ⚠️ نه، یعنی «نُه کارِ لازم» نیست.
         *
         * هشت‌تای اول کارِ واقعی‌اند و نهمی خواندنِ `settings` است که در
         * محصول از کش می‌آید (اینجا کشِ آرایه‌ای است و هر تست خالی شروع
         * می‌شود). اندازه‌گیریِ R65 با کشِ دیتابیسی: ۱۰ ← ۸.
         */
        $this->assertLessThanOrEqual(
            9,
            $profile['count'],
            "GET /notifications: {$profile['count']} کوئری.\n  ".implode("\n  ", $profile['queries']),
        );
    }

    /**
     * ⚠️ حالتِ خالی جدا سنجیده می‌شود، چون رفعِ `whereIn([])` فقط همین‌جا
     * دیده می‌شود: با اطلاعیه‌ی موجود، آن کوئری به هر حال اجرا می‌شود.
     */
    public function test_the_polled_endpoint_touches_nothing_extra_when_there_is_no_news(): void
    {
        [$user] = $this->tenant(withAnnouncements: false);

        $profile = $this->countQueries(
            fn () => $this->actingAs($user)->getJson('/api/v1/notifications')->assertOk()
        );

        $emptyIn = array_values(array_filter(
            $profile['queries'],
            fn (string $sql): bool => str_contains($sql, '0 = 1'),
        ));

        $this->assertSame([], $emptyIn, 'کوئری‌ای با فهرستِ خالی اجرا شد؛ رفت‌وبرگشتی که قطعاً خالی برمی‌گردد.');
    }

    /** ⚠️ همان کوئری نباید دو بار در یک درخواست اجرا شود. */
    public function test_the_polled_endpoint_never_runs_the_same_query_twice(): void
    {
        [$user] = $this->tenant();

        $profile = $this->countQueries(
            fn () => $this->actingAs($user)->getJson('/api/v1/notifications')->assertOk()
        );

        $duplicates = array_keys(array_filter(
            array_count_values($profile['queries']),
            fn (int $times): bool => $times > 1,
        ));

        $this->assertSame([], $duplicates, "کوئریِ تکراری در مسیری که هر دقیقه زده می‌شود:\n  ".implode("\n  ", $duplicates));
    }

    // ─────────────────────────────────────────────────────────────────────
    //  گزارشِ پایه
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 🔴 تا پیش از R65، `web_vitals` جدولی بود که فقط **نوشته** می‌شد.
     *
     * `WebVital::percentile75()` برای همین ساخته شده بود و هیچ فراخوانی
     * نداشت. این تست می‌سنجد که مسیرِ خواندن واقعاً وجود دارد — نه اینکه
     * دستور فقط اجرا شود.
     */
    public function test_the_baseline_report_reads_the_collected_vitals(): void
    {
        foreach (range(1, 40) as $i) {
            WebVital::create([
                'metric' => 'LCP',
                // ⚠️ صدکِ ۷۵ از این توزیع ‎۴۰۰۰ می‌شود، نه میانگینِ ‎۲۵۰۰
                // ۲۸ خوب و ۱۲ بد: صدکِ ۷۵ روی مقدارِ بد می‌افتد
                'value' => $i <= 28 ? 1000 : 4000,
                'rating' => $i <= 28 ? 'good' : 'poor',
                'path' => '/',
                'device' => 'mobile',
            ]);
        }

        Artisan::call('perf:baseline');
        $output = Artisan::output();

        $this->assertStringContainsString('4,000', $output, 'صدکِ ۷۵ در گزارش نیست.');
        $this->assertStringContainsString('40', $output, 'تعدادِ نمونه در گزارش نیست.');
    }

    /** ⚠️ نبودِ داده باید صریح گفته شود؛ جدولِ خالی «همه‌چیز خوب است» خوانده می‌شود. */
    public function test_the_report_says_so_when_nothing_was_collected(): void
    {
        Artisan::call('perf:baseline');

        $this->assertStringContainsString('هیچ سنجه‌ای', Artisan::output());
    }

    /** سیاهه‌ی جدول‌های رشدی نباید به جدولِ ناموجود اشاره کند. */
    public function test_every_watched_table_exists(): void
    {
        $missing = [];

        foreach (array_keys((new ReflectionClass(PerformanceBaseline::class))->getConstant('GROWING_TABLES')) as $table) {
            if (! Schema::hasTable($table)) {
                $missing[] = $table;
            }
        }

        $this->assertSame([], $missing, 'گزارشِ پایه به جدولی اشاره می‌کند که وجود ندارد.');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  کمکی
    // ─────────────────────────────────────────────────────────────────────

    /**
     * همه‌ی تصویرهای منتشرشده: هم `public/images` و هم آن‌هایی که از
     * `resources/images` وارد باندل می‌شوند.
     *
     * @return array<string, int>
     */
    private function images(): array
    {
        $found = [];

        foreach ([public_path('images'), resource_path('images')] as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach ((array) glob($directory.DIRECTORY_SEPARATOR.'*') as $file) {
                if (is_string($file) && is_file($file)) {
                    $found[$file] = (int) filesize($file);
                }
            }
        }

        return $found;
    }

    /**
     * مقادیرِ پیش‌فرضِ ویژگی‌های یک کلاس.
     *
     * ⚠️ `newInstanceWithoutConstructor` روی کلاسِ **انتزاعی** کار نمی‌کند و
     * ساختنِ نمونه‌ی واقعی هم آرگومانِ سازنده می‌خواهد. مقدارِ پیش‌فرض دقیقاً
     * همان چیزی است که لاراول می‌خواند.
     *
     * @return array<string, mixed>
     */
    private function defaults(string $class): array
    {
        return (new ReflectionClass($class))->getDefaultProperties();
    }

    /** @return list<class-string> */
    private function queueableJobs(): array
    {
        $classes = [];

        foreach ((array) glob(app_path('Jobs').DIRECTORY_SEPARATOR.'*.php') as $file) {
            $name = basename((string) $file, '.php');

            if ($name === 'BaseJob') {
                continue;
            }

            $classes[] = 'App\\Jobs\\'.$name;
        }

        $this->assertNotEmpty($classes, 'هیچ کارِ صفی پیدا نشد؛ الگوی مسیر خراب است.');

        return $classes;
    }

    /**
     * @return array{count: int, queries: list<string>}
     */
    private function countQueries(callable $request): array
    {
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        $request();

        $queries = array_map(
            fn (array $row): string => (string) preg_replace('/\s+/', ' ', (string) $row['query']),
            DB::connection()->getQueryLog(),
        );

        DB::connection()->disableQueryLog();

        return ['count' => count($queries), 'queries' => array_values($queries)];
    }

    /** @return array{0: User, 1: Complex} */
    private function tenant(bool $withAnnouncements = true): array
    {
        $complex = Complex::factory()->create();

        $user = User::factory()->create([
            'role' => UserRole::ComplexAdmin->value,
            'complex_id' => $complex->id,
            'is_active' => true,
        ]);

        if ($withAnnouncements) {
            foreach (range(1, 5) as $i) {
                Announcement::create([
                    'complex_id' => $complex->id,
                    'title' => "اطلاعیه {$i}",
                    'body' => 'متن',
                    'audience' => 'all',
                    'is_pinned' => false,
                    'is_active' => true,
                    'published_at' => now(),
                    'created_by' => $user->id,
                ]);
            }
        }

        return [$user, $complex];
    }

    /** @return array<int, string> */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
