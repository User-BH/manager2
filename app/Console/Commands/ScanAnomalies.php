<?php

namespace App\Console\Commands;

use App\Services\Health\AnomalyReport;
use App\Services\Health\HealthAlerter;
use App\Services\Health\HealthReport;
use Illuminate\Console\Command;

/**
 * سنجشِ نشانه‌ها و اعلامِ هشدار (R64).
 *
 * ─── چه چیزی کم بود ────────────────────────────────────────────────────────
 * R43 چهار مسیرِ خرابی را می‌گرفت: دیتابیس، کش، دیسک و صف. همه‌شان
 * سنجه‌های **زیرساختی** بودند — چیزهایی که وقتی خراب شوند، فوراً معلوم
 * می‌شود.
 *
 * ⚠️ چهار خرابیِ این دستور از جنسِ دیگری‌اند: هیچ‌کدام `/up` را قرمز
 * نمی‌کنند و هیچ‌کدام استثنایی در لاگ نمی‌گذارند.
 *
 *   • خطایی که در حلقه تکرار می‌شود → یک ردیف در `error_events`
 *   • درگاهِ بانکی که پاسخ نمی‌دهد  → ردیف‌های `failed` و `pending`
 *   • سرویسِ پیامکی که قطع است     → هیچ ردی، هیچ‌جا
 *   • بکاپی که ساخته نمی‌شود        → ستونی که کسی نمی‌خواندش
 *
 * همه‌شان با نگاه‌کردن به سرور سالم به نظر می‌رسند.
 *
 * ─── چرا دستورِ جدا از `health:check` ──────────────────────────────────────
 * دلیلش در `App\Services\Health\AnomalyReport` نوشته شده: `/up` باید سبک
 * بماند و این سنجه‌ها هرگز نباید گره را از مدار خارج کنند.
 */
class ScanAnomalies extends Command
{
    protected $signature = 'alerts:scan {--quiet-when-ok : اگر چیزی اعلام نشد چیزی چاپ نکن}';

    protected $description = 'سنجشِ جهشِ خطا، شکستِ پرداخت، شکستِ پیامک، شکستِ بکاپ و کندیِ API';

    public function handle(AnomalyReport $anomalies, HealthAlerter $alerter): int
    {
        $report = $anomalies->run();
        $announced = $alerter->dispatch($report);

        /*
         * ⚠️ همان درسِ `health:check`: در حالتِ زمان‌بندی‌شده فقط وقتی چیزی
         * چاپ می‌شود که هشدارِ **تازه‌ای** اعلام شده باشد.
         *
         * وگرنه خفه‌کن درست کار می‌کند ولی cron هر پانزده دقیقه یک خروجی
         * می‌سازد — یعنی همان سیلی که خفه‌کن برای جلوگیری از آن ساخته شده،
         * از مسیرِ دیگری برمی‌گردد.
         */
        if (! $this->option('quiet-when-ok') || $announced !== []) {
            $this->table(
                ['نشانه', 'وضعیت', 'شرح', 'میلی‌ثانیه'],
                collect($report['checks'])->map(fn (array $check, string $name): array => [
                    $name,
                    $check['status'],
                    $check['detail'] ?? '—',
                    $check['ms'] ?? '—',
                ])->values()->all(),
            );
        }

        if ($announced !== []) {
            $this->warn('هشدار اعلام شد برای: '.implode('، ', array_keys($announced)));
        }

        /*
         * ⚠️ فقط `down` کدِ خروجیِ ناموفق می‌دهد — و در این گزارش تنها
         * قطعیِ کاملِ پیامک به آن می‌رسد، چون یعنی درِ ورودیِ سامانه بسته
         * است.
         *
         * اگر `degraded` هم ناموفق حساب می‌شد، اولین درگاهِ بانکیِ شلوغ یک
         * ایمیلِ خطای cron در هر ربع ساعت می‌ساخت و اپراتور ظرفِ یک هفته
         * یاد می‌گرفت خروجیِ این cron را نخواند.
         */
        return $report['status'] === HealthReport::DOWN
            ? self::FAILURE
            : self::SUCCESS;
    }
}
