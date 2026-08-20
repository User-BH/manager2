<?php

namespace App\Console\Commands;

use App\Models\GeneratedDocument;
use App\Support\PrivateFiles;
use Illuminate\Console\Command;

/**
 * پاک‌کردنِ سندهای تولیدشده‌ی قدیمی (R28).
 *
 * ─── چرا لازم است ──────────────────────────────────────────────────────────
 * هر دسته‌ی قبض یک PDFِ چندصد صفحه‌ای روی دیسک است. اگر رها شوند، همان
 * مشکلی پیش می‌آید که برای بکاپ‌ها پیش آمد: پوشه‌ای که کسی نگاهش نمی‌کند و
 * ماه‌به‌ماه بزرگ‌تر می‌شود. این سندها هم **قابلِ بازتولیدند** (پارامترهایشان
 * ذخیره شده)، پس نگه‌داشتنِ ابدی‌شان هیچ چیزی به دست نمی‌دهد.
 *
 * ─── چرا ردیفِ ناتمام هم پاک می‌شود ────────────────────────────────────────
 * ردیفِ `pending` که کارگر هرگز برنداشته (مثلاً چون کارگر بالا نبوده) تا ابد
 * در فهرست می‌ماند و کاربر منتظرِ سندی می‌نشیند که نمی‌آید.
 */
class PruneGeneratedDocuments extends Command
{
    protected $signature = 'documents:prune {--days=14 : سندهای قدیمی‌تر از این تعداد روز}';

    protected $description = 'حذف سندهای PDF تولیدشده‌ی قدیمی و ردیف‌های ناتمام';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $documents = GeneratedDocument::withoutGlobalScopes()
            ->where('created_at', '<', $cutoff)
            ->get();

        $files = 0;

        foreach ($documents as $document) {
            // فایل ممکن است پیش‌تر دستی پاک شده باشد؛ نبودنش خطا نیست
            if ($document->path && PrivateFiles::disk()->exists($document->path)) {
                PrivateFiles::disk()->delete($document->path);
                $files++;
            }

            $document->delete();
        }

        $this->info(sprintf(
            '%d سند حذف شد (%d فایل از دیسک پاک شد).',
            $documents->count(),
            $files,
        ));

        return self::SUCCESS;
    }
}
