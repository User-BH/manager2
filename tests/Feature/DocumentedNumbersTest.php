<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Income;
use App\Models\Unit;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tests\TestCase;

/**
 * هر عددی که در مستندات ادعا شده، از خودِ کد دوباره محاسبه می‌شود (فاز ۰).
 *
 * ─── 🔴 چرا این محافظ لازم شد ──────────────────────────────────────────────
 * فاز ۰ سه عددِ نادرست در گزارش‌ها پیدا کرد که هیچ‌کدام خطا نمی‌دادند:
 *
 *   ① «طولانی‌ترین کار ۱۰۲۰ ثانیه timeout دارد» — در واقع ۹۰۰ بود
 *   ② «≈ ۱ درخواست بر ثانیه» — درست بود، ولی بی‌مبنا و بدونِ واحد
 *   ③ جدولِ جمع‌بندیِ نقشه با ردیف‌های خودش نمی‌خواند
 *
 * ⚠️ هر سه از یک جنس‌اند: عددی که یک بار درست نوشته شد و بعد کد عوض شد.
 * سند خطا نمی‌دهد، تست نداشت، و کسی دوباره نخواندش.
 *
 * پس این تست اعداد را **از منبعشان استخراج می‌کند** و با نوشته می‌سنجد.
 */
class DocumentedNumbersTest extends TestCase
{
    private const PERF = 'docs/FINAL_PERFORMANCE_REPORT.md';

    private const INVENTORY = 'docs/CANONICAL_INVENTORY.md';

    /**
     * 🔴 بلندترین `$timeout` کارها باید همانی باشد که سند می‌گوید.
     *
     * ⚠️ این دقیقاً همان عددی است که در گزارش غلط نوشته شده بود. اگر روزی
     * کارِ سنگین‌تری اضافه شود و سند به‌روز نشود، همین‌جا قرمز می‌کند.
     */
    public function test_the_longest_job_timeout_matches_the_documents(): void
    {
        $longest = max($this->jobTimeouts());

        $this->assertSame(
            900,
            $longest,
            'بلندترین timeoutِ کارها عوض شده؛ گزارشِ کارایی و سیاههٔ مرجع باید به‌روز شوند.',
        );

        foreach ([self::PERF, self::INVENTORY] as $doc) {
            $this->assertStringContainsString(
                $this->persian((string) $longest),
                $this->read($doc),
                "سند «{$doc}» عددِ بلندترین timeout را ندارد.",
            );
        }
    }

    /**
     * ⚠️ `retry_after` باید از بلندترین کار بزرگ‌تر باشد — با حاشیه.
     *
     * 🔴 مرزِ شناخته‌شده: اینجا فقط **وجودِ** حاشیه سنجیده می‌شود، نه کافی
     * بودنش. الزامِ حاشیهٔ صریح کارِ فاز ۷ است و آنجا سنجیده می‌شود.
     */
    public function test_the_queue_retry_window_clears_the_longest_job(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');
        $longest = max($this->jobTimeouts());

        $this->assertGreaterThan($longest, $retryAfter);

        $this->assertSame(
            120,
            $retryAfter - $longest,
            'حاشیهٔ retry_after عوض شده؛ عددِ مستندشده (۱۲۰ ثانیه) باید به‌روز شود.',
        );
    }

    /**
     * 🔴 فاصله‌های polling در سند باید همان چیزی باشند که در کد است.
     *
     * ⚠️ ادعای «≈ ۱ درخواست بر ثانیه» در گزارش بود بی‌آنکه معلوم باشد بر
     * کاربر است یا کلِ سامانه. حالا مبنا نوشته شده — و این تست نمی‌گذارد
     * مبنا از کد جدا بیفتد.
     *
     * @return iterable<string, array{0: string, 1: string, 2: int}>
     */
    public static function pollingSources(): iterable
    {
        yield 'زنگولهٔ اعلان' => ['resources/js/shared/stores/notificationStore.ts', 'POLL_MS', 60_000];
        yield 'پیام‌رسان' => ['resources/js/features/messaging/messenger/MessengerPage.tsx', 'POLL_INTERVAL', 8_000];
    }

    public function test_the_polling_intervals_match_the_code(): void
    {
        foreach (self::pollingSources() as $name => [$path, $constant, $expected]) {
            $source = $this->read($path);

            $this->assertSame(
                1,
                preg_match('/const\s+'.preg_quote($constant, '/').'\s*=\s*([0-9_]+)/', $source, $m),
                "ثابتِ «{$constant}» در «{$path}» پیدا نشد.",
            );

            $this->assertSame(
                $expected,
                (int) str_replace('_', '', $m[1]),
                "فاصلهٔ «{$name}» عوض شده؛ جدولِ polling در مستندات باید از نو محاسبه شود.",
            );
        }
    }

    /**
     * 🔴 حسابِ بارِ polling باید با فاصله‌های واقعی بخواند.
     *
     * ⚠️ محافظی که فقط بپرسد «عددی نوشته شده؟» پوچ است. اینجا عدد **از نو
     * حساب می‌شود** و با نوشته مقایسه می‌گردد.
     */
    public function test_the_polling_load_arithmetic_still_holds(): void
    {
        $perHour = fn (int $intervalMs): int => (int) round(3_600_000 / $intervalMs);

        $notifications = $perHour(60_000);
        $messenger = $perHour(8_000);
        $serviceWorker = 2;

        $this->assertSame(60, $notifications);
        $this->assertSame(450, $messenger);

        // سناریوی مستندشده: بیست کاربرِ آنلاین، پنج نفر در پیام‌رسان
        $total = 20 * $notifications + 5 * $messenger + 20 * $serviceWorker;

        $this->assertSame(3_490, $total, 'مجموعِ بارِ polling عوض شده.');

        $perSecond = round($total / 3600, 2);
        $this->assertSame(0.97, $perSecond);

        $report = $this->read(self::PERF);

        foreach ([$this->persian((string) $total), $this->persian('0٫97')] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $report,
                "گزارشِ کارایی عددِ «{$needle}» را ندارد؛ حساب و نوشته از هم جدا افتاده‌اند.",
            );
        }
    }

    /**
     * ⚠️ ادعای «هیچ WebSocket و SSEای نیست» باید سنجیده بماند.
     *
     * اگر روزی اضافه شوند، بارِ polling معنای دیگری پیدا می‌کند و جدول
     * بی‌اعتبار می‌شود.
     */
    public function test_no_realtime_transport_was_introduced(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $file) {
            $source = (string) file_get_contents($file);

            // کامنت‌ها کنار گذاشته می‌شوند؛ همان تلهٔ همیشگیِ این پروژه
            $code = (string) preg_replace('~^\s*(//|\*|/\*).*$~mu', '', $source);

            if (preg_match('/\bnew\s+(WebSocket|EventSource)\s*\(/', $code) === 1) {
                $offenders[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen(base_path()) + 1));
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "انتقالِ بی‌درنگ اضافه شده:\n  ".implode("\n  ", $offenders)
            ."\n⚠️ جدولِ بارِ polling در مستندات دیگر معتبر نیست.",
        );
    }

    /**
     * 🔴 پنج یافتهٔ تاریخی باید شاهدشان زنده بماند.
     *
     * ⚠️ برچسبِ «HISTORICAL FINDING RESOLVED» فقط تا وقتی درست است که آن
     * اصلاح هنوز سرِ جایش باشد. بدونِ این تست، برچسب به ادعای بی‌پشتوانه
     * تبدیل می‌شود — همان چیزی که فاز ۰ برای بستنش آمده بود.
     */
    public function test_every_historical_fix_is_still_in_place(): void
    {
        $evidence = [
            /*
             * 🔴 اینجا عمداً **متن** سنجیده نمی‌شود.
             *
             * ⚠️ نسخهٔ اول `str_contains(..., 'SoftDeletes')` بود و از تور
             * رد شد: همان کلمه هم در خطِ `use` بالای فایل هست و هم در یک
             * کامنت. یعنی برداشتنِ خودِ trait از کلاس، تست را قرمز نمی‌کرد.
             *
             * همان تلهٔ تکرارشوندهٔ این پروژه (R52، R67، R69، R71): متنی که
             * مثلِ کد به نظر می‌رسد ولی اجرا نمی‌شود. راهِ درست پرسیدن از
             * **خودِ کلاس** است، نه از فایلش.
             */
            'حذفِ نرمِ دادهٔ مالی' => fn (): bool => collect([Expense::class, Income::class, Unit::class])
                ->every(fn (string $model): bool => in_array(SoftDeletes::class, class_uses_recursive($model), true)),

            'سیاستِ کشِ Service Worker' => fn (): bool => is_file(base_path('tests/js/serviceWorkerPolicy.test.ts')),

            'پوششِ همزمانی' => fn (): bool => substr_count(
                $this->read('tests/Feature/ConcurrencyTest.php'), 'public function test_'
            ) >= 10,

            'قیدهای دیتابیس' => fn (): bool => is_file(base_path(
                'database/migrations/2026_08_26_000002_add_financial_check_constraints.php'
            )),

            'شناسهٔ همبستگی' => fn (): bool => is_file(base_path('app/Http/Middleware/AssignRequestId.php'))
                && str_contains($this->read('app/Logging/RequestContext.php'), "'request_id'"),
        ];

        foreach ($evidence as $finding => $check) {
            $this->assertTrue(
                $check(),
                "شاهدِ «{$finding}» دیگر وجود ندارد، ولی سیاههٔ مرجع آن را "
                .'HISTORICAL FINDING RESOLVED اعلام کرده است.',
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ابزار

    /** @return list<int> */
    private function jobTimeouts(): array
    {
        $timeouts = [];

        foreach (glob(app_path('Jobs/*.php')) ?: [] as $file) {
            if (preg_match('/\$timeout\s*=\s*(\d+)/', (string) file_get_contents($file), $m) === 1) {
                $timeouts[] = (int) $m[1];
            }
        }

        $this->assertNotEmpty($timeouts, 'هیچ کاری با timeout پیدا نشد؛ محافظ پوچ می‌شود.');

        return $timeouts;
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $files = [];

        foreach (['resources/js', 'public'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (in_array($file->getExtension(), ['ts', 'tsx', 'js'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    private function read(string $path): string
    {
        $full = base_path($path);
        $this->assertFileExists($full);

        return (string) file_get_contents($full);
    }

    /** ارقامِ لاتین به فارسی، چون مستندات فارسی‌اند. */
    private function persian(string $number): string
    {
        return str_replace(
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            $number,
        );
    }
}
