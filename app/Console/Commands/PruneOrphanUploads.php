<?php

namespace App\Console\Commands;

use App\Models\Advertisement;
use App\Models\BlogPost;
use App\Models\Message;
use App\Models\Payment;
use App\Models\ServiceRequest;
use App\Models\Subscription;
use App\Services\Backup\BackupManifest;
use App\Services\Blog\BlogRenderer;
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
            ->merge(Message::withoutGlobalScopes()->whereNotNull('attachment_path')->pluck('attachment_path'))
            ->merge(ServiceRequest::withoutGlobalScopes()->whereNotNull('attachment_path')->pluck('attachment_path'))
            ->merge($this->blogImages())
            ->filter()
            ->flip();

        $orphans = [];
        $bytes = 0;

        /*
         * ⚠️ این فهرست تا R58 دستی بود و **دو پوشه را جا انداخته بود**:
         * `messages/` و `requests/`. یعنی پیوستِ پیام‌ها و درخواست‌های خدمات
         * هرگز جمع نمی‌شدند و برای همیشه روی دیسک می‌ماندند.
         *
         * حالا از `BackupManifest::FILE_ROOTS` می‌آید — همان فهرستی که
         * آرشیوِ بکاپ از آن می‌خواند. پس اگر روزی پوشه‌ی تازه‌ای اضافه شود،
         * هم بکاپ می‌شود و هم هرس؛ نه اینکه یکی یادش بماند و دیگری نه.
         */
        foreach (array_keys(BackupManifest::FILE_ROOTS) as $directory) {
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

    /**
     * تصویرهای بلاگ: کاور و هر تصویرِ داخلِ متن (مرحلهٔ ۷ · فاز ۷).
     *
     * 🔴 پوشه‌ی `blog/` از R58 در `FILE_ROOTS` بود ولی این فهرست هیچ‌کدام از
     * تصویرهایش را نمی‌شناخت؛ پس `--delete` **همه‌ی تصویرهای بلاگ** را یتیم
     * می‌دید و پاک می‌کرد. تصویرِ داخلِ متن ستونی ندارد و فقط در مارک‌داون
     * است، پس از متن خوانده می‌شود — هم پیش‌نویس‌ها، هم منتشرشده‌ها.
     *
     * @return list<string>
     */
    private function blogImages(): array
    {
        $paths = [];

        BlogPost::query()->select(['id', 'cover_path', 'body'])->chunkById(200, function ($posts) use (&$paths): void {
            foreach ($posts as $post) {
                if ($post->cover_path) {
                    $paths[] = $post->cover_path;
                }

                preg_match_all('#'.preg_quote(BlogRenderer::MEDIA_PREFIX, '#').'([A-Za-z0-9]{40}\.[a-z]+)#', (string) $post->body, $m);
                foreach ($m[1] as $file) {
                    $paths[] = 'blog/'.$file;
                }
            }
        });

        return $paths;
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1).' MB'
            : round($bytes / 1024, 1).' KB';
    }
}
