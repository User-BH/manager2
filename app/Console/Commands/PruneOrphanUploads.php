<?php

namespace App\Console\Commands;

use App\Models\Advertisement;
use App\Models\Payment;
use App\Models\Subscription;
use App\Support\PrivateFiles;
use Illuminate\Console\Command;

/**
 * پاک‌کردنِ فایل‌هایی که هیچ ردیفی در دیتابیس به آن‌ها اشاره نمی‌کند (R19).
 *
 * ─── چرا لازم است ──────────────────────────────────────────────────────────
 * نشتِ اصلی در R19 بسته شد (`Uploads::keepIf`)، ولی نصب‌هایی که از قبل کار
 * می‌کرده‌اند زباله‌ی انباشته دارند و هیچ مسیری برای پاک‌کردنشان وجود نداشت.
 * این دستور همان‌ها را جمع می‌کند.
 *
 * ⚠️ **پیش‌فرض فقط گزارش می‌دهد.** حذفِ فایل برگشت‌پذیر نیست، پس باید صریح
 * خواسته شود (`--delete`).
 */
class PruneOrphanUploads extends Command
{
    protected $signature = 'uploads:prune-orphans
                            {--delete : واقعاً حذف کن (پیش‌فرض فقط گزارش است)}';

    protected $description = 'یافتن فایل‌های آپلودی که ردیف متناظرشان در دیتابیس نیست';

    public function handle(): int
    {
        $disk = PrivateFiles::disk();

        /*
         * مسیرهایی که دیتابیس می‌شناسد. `withoutGlobalScopes` لازم است وگرنه
         * دامنه‌ی مستأجر فقط فایل‌های یک مجتمع را «شناخته‌شده» می‌بیند و
         * بقیه بی‌گناه حذف می‌شوند — دقیقاً همان اشتباهی که این دستور را
         * خطرناک می‌کند.
         */
        $known = collect()
            ->merge(Payment::withoutGlobalScopes()->whereNotNull('receipt_path')->pluck('receipt_path'))
            ->merge(Subscription::withoutGlobalScopes()->whereNotNull('receipt_path')->pluck('receipt_path'))
            ->merge(Advertisement::withoutGlobalScopes()->whereNotNull('image_path')->pluck('image_path'))
            ->filter()
            ->flip();

        $orphans = [];
        $bytes = 0;

        foreach (['receipts', 'subscription-receipts', 'ads'] as $directory) {
            foreach ($disk->allFiles($directory) as $file) {
                if ($known->has($file)) {
                    continue;
                }

                /*
                 * فایل‌های خیلی تازه رد می‌شوند: ممکن است همین حالا درخواستی
                 * فایل را نوشته و هنوز ردیفش را نساخته باشد. حذفشان یعنی
                 * خراب‌کردنِ یک آپلودِ کاملاً سالم.
                 */
                if ($disk->lastModified($file) > now()->subHour()->getTimestamp()) {
                    continue;
                }

                $orphans[] = $file;
                $bytes += $disk->size($file);
            }
        }

        if ($orphans === []) {
            $this->info('هیچ فایلِ یتیمی پیدا نشد.');

            return self::SUCCESS;
        }

        $this->warn(count($orphans).' فایلِ یتیم ('.$this->humanBytes($bytes).')');

        foreach (array_slice($orphans, 0, 20) as $file) {
            $this->line('  '.$file);
        }

        if (count($orphans) > 20) {
            $this->line('  … و '.(count($orphans) - 20).' مورد دیگر');
        }

        if (! $this->option('delete')) {
            $this->newLine();
            $this->comment('چیزی حذف نشد. برای حذف واقعی: php artisan uploads:prune-orphans --delete');

            return self::SUCCESS;
        }

        $disk->delete($orphans);
        $this->info(count($orphans).' فایل حذف شد ('.$this->humanBytes($bytes).' آزاد شد).');

        return self::SUCCESS;
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1).' MB'
            : round($bytes / 1024, 1).' KB';
    }
}
