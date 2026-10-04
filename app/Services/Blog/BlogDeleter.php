<?php

namespace App\Services\Blog;

use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Support\Audit;
use App\Support\PrivateFiles;
use App\Support\SystemSettings;
use Illuminate\Support\Facades\DB;

/**
 * حذفِ **کاملِ** یک مقاله (مرحلهٔ ۸ · فاز ۳ · بندِ ۱۵).
 *
 * پیش از این حذف از پنل فقط ردیف را برمی‌داشت (پیوندِ برچسب‌ها با cascade
 * می‌رفت و کش باطل می‌شد) و سه چیز جا می‌ماند:
 *
 *   - **تصویرها** روی دیسک، تا هرسِ دستی؛
 *   - **برچسبی** که دیگر هیچ مقاله‌ای نداشت؛
 *   - و بدتر: اگر مقاله از `resources/content/blog` آمده بود، `blog:import`
 *     ِ استقرارِ بعدی **دوباره می‌ساختش** — نامکش دیگر در پایگاه‌داده نبود.
 *
 * حالا هر سه بسته‌اند. تصویری که مقاله‌ی دیگری هم به آن اشاره می‌کند (همان
 * فایل با همان sha1) می‌ماند.
 */
class BlogDeleter
{
    /** نامک‌هایی که عمداً حذف شده‌اند؛ `BlogImporter` آن‌ها را دوباره نمی‌سازد */
    public const DELETED_SLUGS_KEY = 'blog_deleted_slugs';

    /** @return array{images: int, tags: int} */
    public function delete(BlogPost $post): array
    {
        $images = BlogMedia::pathsOf($post->cover_path, $post->body);
        $tagIds = $post->tags()->pluck('blog_tags.id')->all();

        $report = DB::transaction(function () use ($post, $tagIds): array {
            Audit::log('blog.deleted', 'حذفِ مقاله‌ی بلاگ', null, ['post' => $post->id, 'title' => $post->title, 'slug' => $post->slug]);

            $deleted = SystemSettings::getJson(self::DELETED_SLUGS_KEY);
            SystemSettings::set(self::DELETED_SLUGS_KEY, array_values(array_unique([...$deleted, $post->slug])));

            $post->delete();

            $orphanTags = BlogTag::whereKey($tagIds)->whereDoesntHave('posts')->pluck('id');
            BlogTag::whereKey($orphanTags)->delete();

            return ['tags' => $orphanTags->count()];
        });

        // پس از تراکنش: فایل برنمی‌گردد، پس فقط وقتی ردیف واقعاً رفته
        $stillUsed = BlogMedia::referenced();
        $disk = PrivateFiles::disk();
        $removed = 0;
        foreach (array_diff($images, $stillUsed) as $path) {
            if ($disk->exists($path) && $disk->delete($path)) {
                $removed++;
            }
        }

        BlogCache::bump();

        return ['images' => $removed, 'tags' => $report['tags']];
    }

    /** @return list<string> */
    public static function deletedSlugs(): array
    {
        return array_values(array_filter(SystemSettings::getJson(self::DELETED_SLUGS_KEY), 'is_string'));
    }
}
