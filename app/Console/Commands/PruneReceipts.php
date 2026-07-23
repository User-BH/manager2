<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * پاک‌کردن فایل‌های رسیدِ یتیم.
 *
 * مدل‌ها هنگام حذف، فایل خودشان را پاک می‌کنند؛ ولی حذف‌های آبشاریِ
 * دیتابیس (حذف واحد یا مجتمع، که پرداخت‌هایش را با ON DELETE CASCADE
 * می‌برد) رویداد Eloquent را اجرا نمی‌کنند و فایل روی دیسک جا می‌ماند.
 *
 * این دستور فایل‌هایی را پاک می‌کند که هیچ ردیفی در دیتابیس به آن‌ها اشاره
 * نمی‌کند. نگه‌داشتن اسناد مالیِ کسانی که دیگر ساکن نیستند، هم فضا می‌گیرد و
 * هم مسئله‌ی حریم خصوصی است.
 */
class PruneReceipts extends Command
{
    private const DIRECTORIES = ['receipts', 'subscription-receipts'];

    protected $signature = 'receipts:prune {--dry-run : فقط گزارش بده، چیزی پاک نکن}';

    protected $description = 'فایل‌های رسیدی که دیگر رکوردی در دیتابیس ندارند را پاک می‌کند';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $dryRun = (bool) $this->option('dry-run');

        // مسیرهای زنده را یک‌بار می‌خوانیم تا برای هر فایل کوئری نزنیم
        $referenced = Payment::whereNotNull('receipt_path')->pluck('receipt_path')
            ->merge(Subscription::whereNotNull('receipt_path')->pluck('receipt_path'))
            ->flip();

        $removed = 0;
        $bytes = 0;

        foreach (self::DIRECTORIES as $directory) {
            if (! $disk->exists($directory)) {
                continue;
            }

            foreach ($disk->allFiles($directory) as $path) {
                if ($referenced->has($path)) {
                    continue;
                }

                $bytes += $disk->size($path);
                $removed++;

                if ($dryRun) {
                    $this->line("یتیم: {$path}");
                } else {
                    $disk->delete($path);
                }
            }
        }

        $megabytes = round($bytes / 1048576, 2);
        $this->info($dryRun
            ? "{$removed} فایل یتیم پیدا شد ({$megabytes} مگابایت) — چیزی پاک نشد."
            : "{$removed} فایل یتیم پاک شد ({$megabytes} مگابایت آزاد شد).");

        return self::SUCCESS;
    }
}
