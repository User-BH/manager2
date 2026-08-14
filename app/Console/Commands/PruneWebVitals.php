<?php

namespace App\Console\Commands;

use App\Models\WebVital;
use Illuminate\Console\Command;

/**
 * پاک‌سازیِ سنجه‌های کاراییِ کهنه (R38).
 *
 * ─── چرا لازم است ──────────────────────────────────────────────────────────
 * هر بازدید تا پنج ردیف می‌سازد. سایتی با روزی هزار بازدید، ماهی حدودِ
 * ۱۵۰ هزار ردیف تولید می‌کند — و این جدول هیچ سقفِ طبیعی ندارد.
 *
 * ─── چرا ۹۰ روز ────────────────────────────────────────────────────────────
 * پنجره‌ی خودِ گوگل برای گزارشِ تجربه‌ی کاربر ۲۸ روز است. نگه‌داشتنِ سه برابرِ
 * آن یعنی می‌شود روند را هم دید («از ماهِ پیش بهتر شده؟») بدونِ اینکه جدول
 * بی‌مرز رشد کند.
 */
class PruneWebVitals extends Command
{
    protected $signature = 'web-vitals:prune {--days=90 : چند روز نگه داشته شود}';

    protected $description = 'حذف سنجه‌های Core Web Vitals قدیمی‌تر از بازه‌ی مشخص';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));

        $deleted = WebVital::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->info("{$deleted} سنجه‌ی قدیمی‌تر از {$days} روز حذف شد.");

        return self::SUCCESS;
    }
}
