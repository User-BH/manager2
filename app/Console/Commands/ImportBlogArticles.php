<?php

namespace App\Console\Commands;

use App\Services\Blog\BlogImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * مقاله‌های همراهِ کد را وارد می‌کند (مرحلهٔ ۷ · فاز ۷).
 *
 * `scripts/deploy.sh` و `scripts/setup.sh` پس از migrate اجرایش می‌کنند. اجرای
 * دوباره بی‌خطر است: مقاله‌ی موجود دست نمی‌خورد (شاید ادمین ویرایشش کرده)،
 * مگر با `--force`. جزئیات در `BlogImporter`.
 */
class ImportBlogArticles extends Command
{
    protected $signature = 'blog:import
        {--force : مقاله‌های موجود را هم با نسخه‌ی فایل بازنویسی کن}';

    protected $description = 'واردکردنِ مقاله‌های بلاگ از resources/content/blog';

    public function handle(BlogImporter $importer): int
    {
        try {
            $report = $importer->import((bool) $this->option('force'));
        } catch (Throwable $e) {
            $this->error('واردکردنِ مقاله‌ها ناموفق بود: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'مقاله‌های بلاگ: %d تازه، %d بازنویسی، %d از قبل بود، %d عمداً حذف‌شده؛ %d تصویر ترمیم شد.',
            count($report['created']),
            count($report['updated']),
            count($report['skipped']),
            count($report['deleted']),
            $report['restored'],
        ));

        return self::SUCCESS;
    }
}
