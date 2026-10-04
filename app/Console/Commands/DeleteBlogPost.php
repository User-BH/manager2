<?php

namespace App\Console\Commands;

use App\Models\BlogPost;
use App\Services\Blog\BlogDeleter;
use Illuminate\Console\Command;

/**
 * حذفِ کاملِ مقاله از خطِ فرمان (مرحلهٔ ۸ · فاز ۳ · بندِ ۱۵).
 *
 * برای مقاله‌ای مثلِ «تست توسط علی» که در پایگاه‌داده‌ی سرورِ اصلی است: همان
 * `BlogDeleter` ِ پنل — ردیف، پیوندِ برچسب، برچسبِ بی‌مقاله، تصویرهای
 * بی‌استفاده، کش — و نامک در فهرستِ «عمداً حذف‌شده» تا `blog:import` برش
 * نگرداند. پیش‌نویس هم پیدا می‌شود (در صفحه‌ی عمومی دیده نمی‌شود ولی در
 * پایگاه‌داده هست).
 *
 *   php artisan blog:delete --title="تست توسط علی"
 *   php artisan blog:delete --slug=تست-توسط-علی --force
 */
class DeleteBlogPost extends Command
{
    protected $signature = 'blog:delete
        {--title= : عنوانِ دقیقِ مقاله}
        {--slug= : نامکِ مقاله}
        {--force : بی‌پرسش}';

    protected $description = 'حذفِ کاملِ مقاله‌ی بلاگ: ردیف، برچسب‌های بی‌مقاله، تصویرهای بی‌استفاده و کش';

    public function handle(BlogDeleter $deleter): int
    {
        $title = trim((string) $this->option('title'));
        $slug = trim((string) $this->option('slug'));

        if ($title === '' && $slug === '') {
            $this->error('یکی از --title یا --slug لازم است.');

            return self::INVALID;
        }

        $posts = BlogPost::query()
            ->when($title !== '', fn ($q) => $q->where('title', $title))
            ->when($slug !== '', fn ($q) => $q->where('slug', $slug))
            ->get();

        if ($posts->isEmpty()) {
            $this->warn('مقاله‌ای با این مشخصات در پایگاه‌داده نیست.');

            return self::SUCCESS;
        }

        $this->table(['شناسه', 'عنوان', 'نامک', 'وضعیت', 'انتشار'], $posts->map(fn (BlogPost $p) => [
            $p->id, $p->title, $p->slug, $p->status, $p->published_at?->toDateTimeString() ?? '—',
        ])->all());

        if (! $this->option('force') && ! $this->confirm("این {$posts->count()} مقاله برای همیشه حذف شود؟")) {
            $this->line('چیزی حذف نشد.');

            return self::SUCCESS;
        }

        foreach ($posts as $post) {
            $report = $deleter->delete($post);
            $this->info("حذف شد: {$post->title} — {$report['images']} تصویر، {$report['tags']} برچسبِ بی‌مقاله.");
        }

        return self::SUCCESS;
    }
}
