<?php

namespace App\Console\Commands;

use App\Services\Health\HealthAlerter;
use App\Services\Health\HealthReport;
use Illuminate\Console\Command;

/**
 * اجرای سنجه‌های سلامت از خطِ فرمان و هشداردادن (R43).
 *
 * ─── دو کاربرد ─────────────────────────────────────────────────────────────
 * ① زمان‌بندی‌شده، برای اینکه مشکل بدونِ اینکه کسی نگاه کند اعلام شود.
 * ② دستی، وقتی کسی می‌خواهد ببیند حالِ سرور چطور است.
 *
 * ⚠️ **این دستور نمی‌تواند مرگِ خودِ زمان‌بند را بگیرد.** اگر cron خاموش
 * باشد، این هم اجرا نمی‌شود؛ ابزاری که خودش قربانیِ خرابی است نمی‌تواند
 * گزارشش کند. آن یکی را فقط پایشِ بیرونی می‌گیرد که به `/up` می‌زند و
 * کهنگیِ ضربانِ زمان‌بند را می‌بیند. برای همین هر دو مسیر لازم است.
 */
class CheckHealth extends Command
{
    protected $signature = 'health:check {--quiet-when-ok : اگر همه‌چیز سالم بود چیزی چاپ نکن}';

    protected $description = 'سنجشِ سلامتِ سامانه و اعلامِ مشکل‌ها';

    public function handle(HealthReport $health, HealthAlerter $alerter): int
    {
        $report = $health->run();
        $announced = $alerter->dispatch($report);

        /*
         * ⚠️ در حالتِ زمان‌بندی‌شده، جدول فقط وقتی چاپ می‌شود که هشدارِ
         * تازه‌ای اعلام شده باشد.
         *
         * نسخه‌ی اولِ این دستور هر بار که وضعیت `ok` نبود جدول را چاپ
         * می‌کرد. نتیجه‌اش این بود که خفه‌کنِ هشدار درست کار می‌کرد ولی cron
         * هر پانزده دقیقه یک ایمیلِ خروجی می‌فرستاد — یعنی همان سیلی که
         * خفه‌کن برای جلوگیری از آن ساخته شده بود، از مسیرِ دیگری برمی‌گشت.
         * وقتی دستی اجرا شود (بدونِ پرچم) همیشه چاپ می‌شود.
         */
        $scheduled = (bool) $this->option('quiet-when-ok');

        if (! $scheduled || $announced !== []) {
            $this->table(
                ['سنجه', 'وضعیت', 'شرح', 'میلی‌ثانیه'],
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
         * ⚠️ فقط `down` کدِ خروجیِ ناموفق می‌دهد.
         *
         * اگر `degraded` هم ناموفق حساب می‌شد، هر cronی که این دستور را صدا
         * می‌زند برای «دیسک ۸۶٪» ایمیلِ خطای cron می‌فرستاد — و آن سیلِ
         * هشدار دقیقاً همان چیزی است که باعث می‌شود آدم‌ها هشدارها را
         * نادیده بگیرند.
         */
        return $report['status'] === HealthReport::DOWN
            ? self::FAILURE
            : self::SUCCESS;
    }
}
