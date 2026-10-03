<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListBlogPostsRequest;
use App\Models\BlogCategory;
use App\Models\BlogTag;
use App\Services\Blog\BlogPages;
use App\Services\Blog\PublicBlog;
use App\Support\CanonicalUrl;
use Illuminate\Http\JsonResponse;

/**
 * API ِ عمومیِ بلاگ — ناوبریِ بی‌بارگذاریِ دوباره (مرحلهٔ ۷ · فاز ۷).
 *
 * ─── چرا هم داده و هم HTML ─────────────────────────────────────────────────
 * صفحه‌های بلاگ MPA ِ سرورساید‌اند (برای SEO و بی‌جاوااسکریپت) و این API
 * جایشان را نمی‌گیرد. کارش این است که جست‌وجو، صفحه‌بندی و بازکردنِ مقاله
 * بدونِ بارگذاریِ کاملِ سند انجام شوند:
 *
 *   • `data`: خودِ نتیجه — صفحه‌بندیِ واقعی از پایگاه‌داده، جست‌وجوی واقعی.
 *   • `html`: بدنه‌ی `<main>` از **همان** قالبِ Blade ِ صفحه‌ی سرورساید
 *     (`_index_body`، `_show_body`) — ظاهر یک منبع دارد، نه دو.
 *   • `meta`: عنوان، توضیح، آدرسِ متعارف و robots، تا `<head>` هم درست شود.
 *
 * محافظت: اعتبارسنجی (`ListBlogPostsRequest`)، سقفِ نرخ (`throttle:blog`)،
 * پرچمِ `blog` (خاموش یعنی ۴۰۴، مثلِ صفحه‌ها) و فقط مقاله‌ی منتشرشده —
 * پیش‌نویس حتی برای سوپرادمین از این‌جا بیرون نمی‌آید؛ پیش‌نمایش فقط از صفحه.
 */
class BlogController extends Controller
{
    public function __construct(
        private readonly PublicBlog $blog,
        private readonly BlogPages $pages,
    ) {}

    public function index(ListBlogPostsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $category = isset($validated['category'])
            ? BlogCategory::where('slug', $validated['category'])->firstOrFail()
            : null;
        $tag = $category === null && isset($validated['tag'])
            ? BlogTag::where('slug', $validated['tag'])->firstOrFail()
            : null;
        $search = $category === null && $tag === null ? ($validated['q'] ?? null) : null;

        $page = $this->pages->listing((int) ($validated['page'] ?? 1), $category, $tag, $search);
        $result = $page['view']['result'];

        return response()->json([
            'data' => [
                'items' => $result['items'],
                'total' => $result['total'],
                'page' => $result['page'],
                'lastPage' => $result['lastPage'],
                'perPage' => PublicBlog::PER_PAGE,
                'search' => $search,
                'heading' => $page['view']['heading'],
            ],
            'html' => view('public.blog._index_body', $page['view'])->render(),
            'meta' => $this->meta('listing', $page['seo'], $this->listingPath($category, $tag, $search, $result['page'])),
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $post = $this->blog->find($slug);
        abort_if($post === null, 404);

        $page = $this->pages->article($post);
        unset($post['categoryId'], $post['status']);

        return response()->json([
            'data' => $post,
            'html' => view('public.blog._show_body', $page['view'])->render(),
            'meta' => $this->meta('article', $page['seo'], $post['url']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $seo
     * @return array<string, mixed>
     */
    private function meta(string $view, array $seo, string $path): array
    {
        return [
            'view' => $view,
            // آدرسِ نسبیِ نرمال‌شده — مثلاً صفحه‌ی ۹ِ فهرستِ ۲صفحه‌ای به ۲ برمی‌گردد
            'url' => $path,
            'title' => $seo['title'],
            'description' => $seo['description'],
            'canonical' => $seo['canonical'],
            'robots' => $seo['robots'] ?? null,
            'ogImage' => $seo['og_image'] ?? null,
            // همان مسیرِ راهنمای قالب — «خانه» اول، مثلِ `layouts/public`
            'breadcrumbs' => view('partials.breadcrumb-items', [
                'breadcrumbs' => [['name' => 'خانه', 'url' => CanonicalUrl::base().'/'], ...$seo['breadcrumbs']],
            ])->render(),
        ];
    }

    private function listingPath(?BlogCategory $category, ?BlogTag $tag, ?string $search, int $page): string
    {
        $path = '/blog'.match (true) {
            $category !== null => '/category/'.rawurlencode($category->slug),
            $tag !== null => '/tag/'.rawurlencode($tag->slug),
            default => '',
        };

        $query = array_filter(['q' => $search, 'page' => $page > 1 ? $page : null], fn ($v) => $v !== null);

        return $query === [] ? $path : $path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
