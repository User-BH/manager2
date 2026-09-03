<?php

namespace App\Console\Commands;

use App\Models\WebVital;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * خواندنِ سنجه‌های کاراییِ جمع‌شده (R65).
 *
 * ─── 🔴 نقصی که اندازه‌گیری پیدا کرد ────────────────────────────────────────
 * R38 سنجه‌های Core Web Vitals را از دستگاهِ کاربرانِ واقعی جمع می‌کرد و
 * نود روز نگه می‌داشت. جست‌وجوی کلِ کد نشان داد این داده **فقط نوشته
 * می‌شود**: `WebVitalsController::store` درج می‌کند، `web-vitals:prune`
 * پاک می‌کند، و هیچ‌کس هرگز نمی‌خواندش.
 *
 * حتی `WebVital::percentile75()` — که دقیقاً برای همین ساخته شده بود و
 * توضیحش می‌گفت چرا میانگین جواب اشتباه می‌دهد — **هیچ فراخوانی نداشت**.
 *
 * یعنی سامانه سه ماه دادهٔ میدانی جمع می‌کرد و کسی نمی‌توانست ببیندش.
 *
 * ─── چرا دستور و نه صفحه‌ی پنل ─────────────────────────────────────────────
 * ⚠️ صفحه‌ی پنل کارِ بیشتری است و کارِ این مرحله نیست. مسئله‌ی امروز
 * «نمودار قشنگ نیست» نبود؛ «هیچ راهی برای دیدنِ عدد نیست» بود. یک دستور
 * آن را کاملاً حل می‌کند و کارِ ساختِ نمایشِ گرافیکی سرِ جایش می‌ماند.
 *
 * ─── ⚠️ چه چیزی اینجا **نیست** ─────────────────────────────────────────────
 * زمانِ پاسخِ واقعیِ سرور، مصرفِ CPU و حافظه، و رفتار زیرِ بار. هیچ‌کدام از
 * داخلِ همین پردازه قابلِ اندازه‌گیریِ صادقانه نیستند؛ دلیلشان در
 * `docs/PERFORMANCE.md` و `docs/ALERTING.md` نوشته شده.
 */
class PerformanceBaseline extends Command
{
    protected $signature = 'perf:baseline
                            {--days=28 : پنجره‌ی گزارش}';

    protected $description = 'گزارشِ سنجه‌های کاراییِ جمع‌شده از کاربرانِ واقعی';

    /** جدول‌هایی که بی‌مرز رشد می‌کنند و اندازه‌شان روی کارایی اثر دارد. */
    private const GROWING_TABLES = [
        'web_vitals' => 'سنجه‌ی کارایی (۹۰ روز)',
        'error_events' => 'خطاهای گروه‌شده',
        'sessions' => 'نشست‌ها',
        'cache' => 'کش',
        'jobs' => 'صف',
        'failed_jobs' => 'کارهای شکست‌خورده',
        'audit_logs' => 'سیاهه‌ی ممیزی',
        'notifications' => 'اعلان‌های شخصی',
    ];

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));

        $this->vitals($days);
        $this->tables();
        $this->queue();

        return self::SUCCESS;
    }

    private function vitals(int $days): void
    {
        $this->info("Core Web Vitals — صدکِ ۷۵، {$days} روزِ گذشته");

        $good = (array) config('performance.vitals.good', []);
        $minimum = max(1, (int) config('performance.vitals.min_samples', 30));

        $rows = [];

        foreach (array_keys($good) as $metric) {
            foreach (['mobile', 'desktop'] as $device) {
                $samples = WebVital::query()
                    ->where('metric', $metric)
                    ->where('device', $device)
                    ->where('created_at', '>=', now()->subDays($days))
                    ->count();

                if ($samples === 0) {
                    $rows[] = [$metric, $device, '—', '—', 0, 'داده‌ای نیست'];

                    continue;
                }

                $value = WebVital::percentile75($metric, $device, $days);

                $rows[] = [
                    $metric,
                    $device,
                    $this->format($metric, (float) $value),
                    $this->format($metric, (float) $good[$metric]),
                    $samples,
                    $this->verdict($metric, (float) $value, $samples, $minimum),
                ];
            }
        }

        $this->table(['سنجه', 'دستگاه', 'صدکِ ۷۵', 'سقفِ «خوب»', 'نمونه', 'وضعیت'], $rows);

        /*
         * ⚠️ نبودِ داده باید **صریح** گفته شود، نه با جدولِ خالی رد شود.
         *
         * جدولِ خالی به‌راحتی «همه‌چیز خوب است» خوانده می‌شود، در حالی که
         * دقیقاً یعنی هیچ‌چیز سنجیده نشده.
         */
        if (WebVital::query()->where('created_at', '>=', now()->subDays($days))->count() === 0) {
            $this->warn(
                '⚠️  هیچ سنجه‌ای در این پنجره ثبت نشده است. '
                .'یا سایت هنوز بازدیدکننده‌ی واقعی نداشته، یا ارسالِ سنجه از مرورگر نمی‌رسد.'
            );
        }
    }

    private function tables(): void
    {
        $this->newLine();
        $this->info('حجمِ جدول‌های رشدی');

        $rows = [];

        foreach (self::GROWING_TABLES as $table => $label) {
            // ⚠️ جدولی که هنوز مهاجرتش اجرا نشده نباید کلِ گزارش را بترکاند
            $rows[] = Schema::hasTable($table)
                ? [$table, $label, number_format(DB::table($table)->count())]
                : [$table, $label, 'وجود ندارد'];
        }

        $this->table(['جدول', 'شرح', 'ردیف'], $rows);
    }

    private function queue(): void
    {
        $this->newLine();
        $this->info('صف');

        $this->line('  در انتظار: '.DB::table('jobs')->count());
        $this->line('  شکست‌خورده: '.DB::table('failed_jobs')->count());
    }

    /** CLS بی‌واحد است و سه رقمِ اعشار می‌خواهد؛ بقیه میلی‌ثانیه‌ی صحیح‌اند. */
    private function format(string $metric, float $value): string
    {
        return $metric === 'CLS'
            ? number_format($value, 3)
            : number_format($value).' ms';
    }

    /**
     * ⚠️ کمبودِ نمونه **پیش از** قضاوت گفته می‌شود.
     *
     * صدکِ ۷۵ روی سه نمونه، عددِ سومی است نه صدک. گزارشی که این را نگوید،
     * اپراتور را وادار می‌کند بر اساسِ نویز بهینه‌سازی کند.
     */
    private function verdict(string $metric, float $value, int $samples, int $minimum): string
    {
        $good = (float) config("performance.vitals.good.{$metric}");

        $rating = match (true) {
            $value <= $good => 'خوب',
            $value <= $good * 2 => 'نیاز به بهبود',
            default => 'بد',
        };

        return $samples < $minimum
            ? $rating.' (نمونه کم)'
            : $rating;
    }
}
