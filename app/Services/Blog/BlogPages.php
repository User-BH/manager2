<?php

namespace App\Services\Blog;

use App\Models\BlogCategory;
use App\Models\BlogTag;
use App\Support\CanonicalUrl;

/**
 * داده‌ی دو صفحه‌ی عمومیِ بلاگ — فهرست و مقاله (مرحلهٔ ۷ · فاز ۷).
 *
 * ─── چرا جدا از کنترلر ─────────────────────────────────────────────────────
 * همین داده را دو جا لازم دارند: صفحه‌ی سرورساید (`BlogController`) و
 * API ِ ناوبریِ بی‌بارگذاری (`Api\BlogController`) که همان بدنه را رندر
 * می‌کند و عنوان، توضیح و آدرسِ متعارف را برای به‌روزکردنِ `<head>` برمی‌گرداند.
 * اگر هر کدام نسخه‌ی خودش را می‌ساخت، عنوانِ صفحه‌ی دوم یا `noindex` ِ
 * جست‌وجو دیر یا زود در یکی جا می‌ماند.
 */
class BlogPages
{
    public function __construct(private readonly PublicBlog $blog) {}

    /**
     * @return array{view: array<string, mixed>, seo: array<string, mixed>}
     */
    public function listing(int $page, ?BlogCategory $category, ?BlogTag $tag, ?string $search): array
    {
        $result = $this->blog->page($page, $category, $tag, $search);

        $base = CanonicalUrl::base().'/blog'.match (true) {
            $category !== null => '/category/'.rawurlencode($category->slug),
            $tag !== null => '/tag/'.rawurlencode($tag->slug),
            default => '',
        };

        $pageUrl = fn (int $p) => $p > 1 ? $base.'?page='.$p : $base;
        $heading = match (true) {
            $category !== null => $category->name,
            $tag !== null => '#'.$tag->name,
            $search !== null => 'نتیجه‌ی جست‌وجو',
            default => 'بلاگ '.config('brand.name'),
        };

        $crumbs = [['name' => 'بلاگ', 'url' => CanonicalUrl::base().'/blog']];
        if ($category !== null || $tag !== null) {
            $crumbs[] = ['name' => $heading, 'url' => $base];
        }

        return [
            'view' => [
                'result' => $result,
                'categories' => $this->blog->categories(),
                'heading' => $heading,
                'description' => $category?->description,
                'search' => $search,
                'pageUrl' => $pageUrl,
            ],
            'seo' => [
                'title' => $heading.($result['page'] > 1 ? ' — صفحه‌ی '.$result['page'] : '').' | '.config('brand.name'),
                'description' => $category?->description
                    ?: 'راهنما و تجربه‌ی مدیریتِ ساختمان و مجتمع: شارژ، قبض، حسابداری و زندگیِ آپارتمانی.',
                // هر صفحه‌ی فهرست متعارفِ خودش است (نه صفحه‌ی اول)؛ جست‌وجو ایندکس نمی‌شود
                'canonical' => $pageUrl($result['page']),
                'robots' => $search !== null ? 'noindex,follow' : null,
                'breadcrumbs' => $crumbs,
                'prev' => $result['page'] > 1 ? $pageUrl($result['page'] - 1) : null,
                'next' => $result['page'] < $result['lastPage'] ? $pageUrl($result['page'] + 1) : null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $post  خروجیِ `PublicBlog::find` یا `preview`
     * @return array{view: array<string, mixed>, seo: array<string, mixed>}
     */
    public function article(array $post, bool $preview = false): array
    {
        return [
            'view' => [
                'post' => $post,
                'related' => $preview ? [] : $this->blog->related($post['id'], $post['categoryId']),
                'preview' => $preview,
            ],
            'seo' => [
                'title' => $post['metaTitle'].' | بلاگ '.config('brand.name'),
                'description' => $post['metaDescription'],
                'og_type' => 'article',
                'og_image' => $post['cover'] ? CanonicalUrl::base().$post['cover'] : null,
                'canonical' => $post['canonical'],
                'robots' => $preview ? 'noindex,nofollow' : null,
                'breadcrumbs' => array_values(array_filter([
                    ['name' => 'بلاگ', 'url' => CanonicalUrl::base().'/blog'],
                    $post['category'] ? ['name' => $post['category']['name'], 'url' => CanonicalUrl::base().'/blog/category/'.rawurlencode($post['category']['slug'])] : null,
                    ['name' => $post['title'], 'url' => $post['canonical']],
                ])),
            ],
        ];
    }
}
