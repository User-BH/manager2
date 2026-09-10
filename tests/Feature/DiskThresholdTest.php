<?php

namespace Tests\Feature;

use App\Services\Health\DiskSpace;
use App\Services\Health\HealthReport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * نگاشتِ درصدِ اشغالِ دیسک به شدتِ سلامت (مرحلهٔ ۵، فاز ۱ — S4-27).
 *
 * ─── 🔴 چرا این فایل تازه است، در حالی که خودِ نگاشت قدیمی است ──────────────
 * `HealthReport` از R43 آستانه‌های ۸۵ و ۹۷ را داشت، ولی **هیچ تستی
 * مستقیم نمی‌سنجیدشان**. تنها راهی که اثرشان دیده می‌شد، پر شدنِ دیسکِ
 * واقعیِ یک نفر بود.
 *
 * ⚠️ و دقیقاً همین اتفاق افتاد: در ممیزیِ تجمیعی، دیسکِ ماشینِ توسعه به
 * ۹۸٪ رسید و پنج تستِ سلامت قرمز شدند — تست‌هایی که هیچ‌کدام دربارهٔ دیسک
 * نبودند. بعد دیسک به ۹۳٪ رسید و همان‌ها **بدونِ یک خط تغییرِ کد** سبز
 * شدند.
 *
 * 🔴 یعنی پوشش دو مشکل هم‌زمان داشت: چیزی که باید سنجیده می‌شد سنجیده
 * نمی‌شد، و چیزهایی که نباید به دیسک وابسته می‌بودند، بودند.
 *
 * این فایل نیمهٔ اول را می‌بندد؛ نیمهٔ دوم در `HealthCheckTest` بسته شد.
 */
class DiskThresholdTest extends TestCase
{
    /**
     * درصدِ اشغال ← شدتِ انتظارشده.
     *
     * ⚠️ مرزها **دقیقاً** روی آستانه سنجیده می‌شوند، نه دورِ آن. باگِ
     * `>=` در برابر `>` فقط روی خودِ عدد دیده می‌شود.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function occupancy(): array
    {
        return [
            'دیسکِ خالی' => [0, HealthReport::OK],
            'نیمه‌پر' => [50, HealthReport::OK],
            'یک درصد زیرِ هشدار' => [84, HealthReport::OK],
            'دقیقاً روی هشدار' => [85, HealthReport::DEGRADED],
            'بینِ هشدار و بحران' => [93, HealthReport::DEGRADED],
            'یک درصد زیرِ بحران' => [96, HealthReport::DEGRADED],
            'دقیقاً روی بحران' => [97, HealthReport::DOWN],
            'پرتر از بحران' => [99, HealthReport::DOWN],
            'کاملاً پر' => [100, HealthReport::DOWN],
        ];
    }

    #[DataProvider('occupancy')]
    public function test_each_occupancy_maps_to_its_severity(int $percent, string $expected): void
    {
        $this->fakeDiskAt($percent);

        $disk = app(HealthReport::class)->run()['checks']['disk'];

        $this->assertSame(
            $expected,
            $disk['status'],
            "دیسکِ {$percent}٪ باید «{$expected}» باشد ولی «{$disk['status']}» شد.",
        );

        $this->assertSame($percent, $disk['used_percent']);
    }

    /**
     * 🔴 محیطی که فضای دیسک را نمی‌دهد، **هشدار نمی‌سازد**.
     *
     * ⚠️ «نمی‌دانم» با «پر است» یکی نیست. اگر `null` را `down` حساب
     * می‌کردیم، هر محیطِ محدودشده‌ای سایتِ سالم را از مدار خارج می‌کرد.
     */
    public function test_an_environment_without_disk_metrics_stays_ok(): void
    {
        $this->app->instance(DiskSpace::class, new class extends DiskSpace
        {
            public function usage(string $path): ?array
            {
                return null;
            }
        });

        $disk = app(HealthReport::class)->run()['checks']['disk'];

        $this->assertSame(HealthReport::OK, $disk['status']);
        $this->assertArrayNotHasKey('used_percent', $disk);
    }

    /**
     * ⚠️ آستانه‌ها نباید بی‌سروصدا شل شوند.
     *
     * 🔴 آسان‌ترین راهِ سبزکردنِ یک تستِ سلامت، بالا بردنِ آستانه است — و
     * همان کاری است که سنجه را بی‌معنا می‌کند.
     */
    public function test_the_thresholds_did_not_drift(): void
    {
        $this->assertSame(85, HealthReport::DISK_WARNING_PERCENT);
        $this->assertSame(97, HealthReport::DISK_CRITICAL_PERCENT);
        $this->assertLessThan(
            HealthReport::DISK_CRITICAL_PERCENT,
            HealthReport::DISK_WARNING_PERCENT,
            'هشدار باید پیش از بحران بیاید.',
        );
    }

    /** دیسکی که دقیقاً همان درصد اشغال را نشان می‌دهد. */
    private function fakeDiskAt(int $percent): void
    {
        $this->app->instance(DiskSpace::class, new class($percent) extends DiskSpace
        {
            public function __construct(private readonly int $percent) {}

            public function usage(string $path): ?array
            {
                $total = 1_000_000_000.0;

                return ['total' => $total, 'free' => $total * (100 - $this->percent) / 100];
            }
        });
    }
}
