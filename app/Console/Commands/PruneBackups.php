<?php

namespace App\Console\Commands;

use App\Models\Backup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * نگه‌داشتن چند بکاپ آخر و پاک‌کردن بقیه.
 *
 * هر بکاپ یک فایل JSON کامل از داده است و هیچ سیاست نگه‌داری‌ای نداشت: با
 * چند کلیک روی دکمه‌ی «گرفتن بکاپ»، دیسک سرور پر می‌شد. نگه‌داشتن ابدیِ
 * فایلی که کل اطلاعات ساکنین را دارد، مسئله‌ی حریم خصوصی هم هست.
 */
class PruneBackups extends Command
{
    protected $signature = 'backups:prune {--keep=10 : تعداد بکاپی که از هر گروه نگه داشته می‌شود} {--dry-run}';

    protected $description = 'پاک‌کردن بکاپ‌های قدیمی و نگه‌داشتن چند نسخه‌ی آخر';

    public function handle(): int
    {
        $keep = max(1, (int) $this->option('keep'));
        $dry = (bool) $this->option('dry-run');

        /*
         * گروه‌بندی بر اساس مجتمع: بکاپ‌های سیستمی (complex_id = null) یک گروه
         * و هر مجتمع گروه خودش. وگرنه یک مجتمعِ پرکار می‌توانست سهمیه‌ی بقیه
         * را مصرف کند و بکاپ آن‌ها حذف شود.
         */
        $removed = 0;
        $freed = 0;

        foreach (Backup::get()->groupBy(fn (Backup $b) => $b->complex_id ?? 'system') as $group => $backups) {
            $stale = $backups->sortByDesc('id')->slice($keep);

            foreach ($stale as $backup) {
                $size = (int) $backup->size;

                $this->line(sprintf(
                    '%s [%s] %s (%s کیلوبایت)',
                    $dry ? 'حذف می‌شد:' : 'حذف شد:',
                    $group,
                    $backup->path,
                    number_format($size / 1024, 1),
                ));

                if (! $dry) {
                    if ($backup->path) {
                        Storage::disk($backup->disk ?: 'local')->delete($backup->path);
                    }
                    $backup->delete();
                }

                $removed++;
                $freed += $size;
            }
        }

        $this->info(sprintf(
            '%d بکاپ %s — %s مگابایت آزاد %s.',
            $removed,
            $dry ? 'قابل حذف است' : 'حذف شد',
            number_format($freed / 1024 / 1024, 2),
            $dry ? 'می‌شود' : 'شد',
        ));

        return self::SUCCESS;
    }
}
