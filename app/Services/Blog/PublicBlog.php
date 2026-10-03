<?php

namespace App\Services\Blog;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Support\CanonicalUrl;
use App\Support\Jalali;
use App\Support\PersianText;
use Illuminate\Database\Eloquent\Builder;

/**
 * خواندنِ عمومیِ بلاگ (مرحلهٔ ۶ · فاز ۱۱).
 *
 * همه‌چیز از `BlogPost::visible()` می‌گذرد: پیش‌نویس و زمان‌بندی‌شده از هیچ
 * راهی — فهرست، دسته، برچسب، جست‌وجو، مرتبط، نقشه‌ی سایت — بیرون نمی‌آیند.
 * خروجی آرایه است نه مدل، تا کش‌شدنی باشد و قالب فقط چیزی را ببیند که باید.
 */
class PublicBlog
{
    /**
     * ده مقاله در هر صفحه (مرحلهٔ ۷ · فاز ۷، خواسته‌ی ممیزی؛ پیش از این ۹ و
     * شبکه‌ی سه‌ستونی بود).
     */
    public const PER_PAGE = 10;

    /**
     * کوتاه‌ترین عبارتِ جست‌وجو (فاز ۷).
     *
     * یک حرف تقریباً در همه‌ی مقاله‌ها هست؛ جست‌وجویش فقط درخواستِ بی‌فایده
     * و فهرستِ کامل است. API با ۴۲۲ ردش می‌کند و صفحه پیامِ روشن می‌دهد.
     */
    public const MIN_SEARCH = 2;

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, lastPage: int}
     */
    public function page(int $page, ?BlogCategory $category = null, ?BlogTag $tag = null, ?string $search = null): array
    {
        $term = $search !== null ? $this->term($search) : '';

        // عبارتی که پس از پاک‌سازی چیزی از آن نماند («%») چیزی پیدا نمی‌کند — نه همه‌چیز
        if ($search !== null && $term === '') {
            return ['items' => [], 'total' => 0, 'page' => 1, 'lastPage' => 1];
        }

        // عبارتِ تک‌حرفی: نتیجه‌ای نمی‌دهد و قالب علتش را می‌گوید (فاز ۷)
        if ($search !== null && mb_strlen($term) < self::MIN_SEARCH) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'lastPage' => 1, 'tooShort' => true];
        }

        $build = function () use ($page, $category, $tag, $term): array {
            $query = BlogPost::visible()
                ->with(['category', 'tags'])
                ->when($category, fn (Builder $q) => $q->where('category_id', $category->id))
                ->when($tag, fn (Builder $q) => $q->whereHas('tags', fn (Builder $t) => $t->whereKey($tag->id)))
                ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                    ->where('title', 'like', "%{$term}%")
                    ->orWhere('search_text', 'like', "%{$term}%")))
                ->orderByDesc('published_at')
                ->orderByDesc('id');

            $total = (clone $query)->count();
            $lastPage = max(1, (int) ceil($total / self::PER_PAGE));

            return [
                'items' => $query->forPage(min($page, $lastPage), self::PER_PAGE)->get()
                    ->map(fn (BlogPost $post) => $this->card($post))->all(),
                'total' => $total,
                'page' => min($page, $lastPage),
                'lastPage' => $lastPage,
            ];
        };

        // ⚠️ جست‌وجو کش نمی‌شود: هر عبارتِ تازه یک کلیدِ تازه بود و کش را پر می‌کرد
        if ($term !== '') {
            return $build();
        }

        return BlogCache::remember("page:{$page}:c".($category->id ?? 0).':t'.($tag->id ?? 0), $build);
    }

    /** @return array<string, mixed>|null */
    public function find(string $slug): ?array
    {
        return BlogCache::remember('post:'.md5($slug), function () use ($slug): ?array {
            $post = BlogPost::visible()->with(['category', 'tags'])->where('slug', $slug)->first();

            return $post ? $this->full($post) : null;
        });
    }

    /** مقاله‌ی پیش‌نویس برای پیش‌نمایشِ سوپرادمین — بی‌کش. */
    public function preview(BlogPost $post): array
    {
        return $this->full($post->loadMissing(['category', 'tags']));
    }

    /** @return array<int, array<string, mixed>> */
    /**
     * مقاله‌های مرتبط — بر اساسِ **موضوع**، نه تازگی (مرحلهٔ ۷ · فاز ۷).
     *
     * پیش از این «هم‌دسته اول، بعد تازه‌ترین‌ها» بود؛ با یک دسته‌ی بزرگ، مرتبط
     * یعنی سه مقاله‌ی آخرِ همان دسته، هرچه بودند. حالا امتیاز: هر برچسبِ
     * مشترک ۲، هم‌دسته بودن ۱؛ برابرها با تازگی. مقاله‌ی بی‌هیچ اشتراکی
     * فقط وقتی می‌آید که مرتبطِ کافی نباشد.
     *
     * ⚠️ امتیاز در PHP حساب می‌شود، نه SQL: تعدادِ مقاله‌ها صدها است نه
     * میلیون‌ها، و نتیجه کش می‌شود.
     *
     * @return array<int, array<string, mixed>>
     */
    public function related(int $postId, ?int $categoryId, int $limit = 3): array
    {
        return BlogCache::remember("related:{$postId}", function () use ($postId, $categoryId, $limit): array {
            $tagIds = BlogPost::find($postId)?->tags()->pluck('blog_tags.id')->all() ?? [];

            return BlogPost::visible()
                ->with(['category', 'tags'])
                ->whereKeyNot($postId)
                ->orderByDesc('published_at')
                ->get()
                ->map(fn (BlogPost $post) => [
                    'post' => $post,
                    'score' => 2 * $post->tags->whereIn('id', $tagIds)->count()
                        + ($categoryId !== null && $post->category_id === $categoryId ? 1 : 0),
                ])
                ->sortByDesc('score') // پایدار است: برابرها همان ترتیبِ تازگی را نگه می‌دارند
                ->take($limit)
                ->map(fn (array $row) => $this->card($row['post']))
                ->values()
                ->all();
        });
    }

    public function categories(): array
    {
        return BlogCache::remember('categories', fn () => BlogCategory::query()
            ->withCount(['posts' => fn ($q) => $q->visible()])
            ->orderBy('name')
            ->get()
            ->filter(fn (BlogCategory $c) => $c->posts_count > 0)
            ->map(fn (BlogCategory $c) => ['name' => $c->name, 'slug' => $c->slug, 'count' => (int) $c->posts_count])
            ->values()
            ->all());
    }

    /** @return array<int, array{loc: string, lastmod: string}> */
    public function sitemap(): array
    {
        return BlogPost::visible()
            ->orderByDesc('published_at')
            ->get(['slug', 'updated_at'])
            ->map(fn (BlogPost $post) => [
                'loc' => '/blog/'.rawurlencode($post->slug),
                'lastmod' => $post->updated_at->toDateString(),
            ])
            ->all();
    }

    /**
     * خلاصه‌ی خودکار وقتی نویسنده خلاصه ننوشته: اولین بندِ متن.
     *
     * ⚠️ نه `search_text`: آن برای جست‌وجو یک‌دست شده (نیم‌فاصله و نشانه‌ها
     * رفته‌اند) و «می‌شود» را «میشود» نشان می‌داد.
     */
    private function summary(string $html, int $limit = 180): string
    {
        $text = preg_match('/<p>(.*?)<\/p>/su', $html, $m) ? $m[1] : $html;
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit)).'…' : $text;
    }

    /** عبارتِ جست‌وجو: یک‌دست (ی/ک)، بی‌نویسه‌ی ویژه‌ی LIKE، و محدود. */
    public function term(string $search): string
    {
        return mb_substr(trim(str_replace(['%', '_', '\\'], '', PersianText::normalize($search))), 0, 80);
    }

    /** @return array<string, mixed> */
    private function card(BlogPost $post): array
    {
        return [
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'url' => '/blog/'.rawurlencode($post->slug),
            'excerpt' => $post->excerpt ?: $this->summary($post->body_html),
            'cover' => $post->cover_path ? BlogRenderer::MEDIA_PREFIX.basename($post->cover_path) : null,
            'category' => $post->category ? ['name' => $post->category->name, 'slug' => $post->category->slug] : null,
            'tags' => $post->tags->map(fn (BlogTag $t) => ['name' => $t->name, 'slug' => $t->slug])->all(),
            'publishedAt' => $post->published_at?->toIso8601String(),
            'publishedLabel' => $post->published_at ? Jalali::date($post->published_at) : null,
            'readingMinutes' => $post->reading_minutes,
        ];
    }

    /** @return array<string, mixed> */
    private function full(BlogPost $post): array
    {
        return [
            ...$this->card($post),
            'categoryId' => $post->category_id,
            'bodyHtml' => $post->body_html,
            'toc' => $post->toc ?? [],
            'status' => $post->status,
            'updatedAt' => $post->updated_at->toIso8601String(),
            // ⚠️ نامِ نویسنده عمداً بیرون نمی‌آید (مرحلهٔ ۷ · فاز ۷، خواسته‌ی ممیزی):
            // نه در صفحه، نه در JSON-LD، نه در API. ناشر خودِ ساکناست.
            'metaTitle' => $post->meta_title ?: $post->title,
            'metaDescription' => $post->meta_description ?: ($post->excerpt ?: $this->summary($post->body_html, 155)),
            'canonical' => CanonicalUrl::base().'/blog/'.rawurlencode($post->slug),
        ];
    }
}
