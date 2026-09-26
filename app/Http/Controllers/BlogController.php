<?php

namespace App\Http\Controllers;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Services\Blog\PublicBlog;
use App\Services\Features\FeatureFlags;
use App\Support\CanonicalUrl;
use App\Support\PrivateFiles;
use App\Support\Uploads;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * صفحه‌های عمومیِ بلاگ (مرحلهٔ ۶ · فاز ۱۱).
 *
 * ─── چرا Blade و نه React ────────────────────────────────────────────────────
 * متنِ مقاله در HTMLِ خامِ سرور است: خزنده و دستیارِ هوش مصنوعی که
 * جاوااسکریپت اجرا نمی‌کنند همان را می‌خوانند که خواننده می‌بیند، و صفحه
 * بدونِ JS هم خواندنی است. React فقط سرصفحه، پاصفحه، نوارِ پیشرفت و دکمه‌ی
 * کپیِ پیوند را می‌سازد.
 *
 * ⚠️ خاموش بودنِ پرچمِ `blog` یعنی ۴۰۴ — نه ۴۰۳ِ «موقتاً خاموش»: بلاگی که
 * هنوز راه نیفتاده نباید وجودش را اعلام کند.
 */
class BlogController extends Controller
{
    public function __construct(private readonly PublicBlog $blog) {}

    public function index(Request $request): Response
    {
        $this->ensureEnabled();

        $search = trim((string) $request->query('q', ''));

        return $this->listing($request, null, null, $search !== '' ? $search : null);
    }

    public function category(Request $request, string $slug): Response
    {
        $this->ensureEnabled();

        return $this->listing($request, BlogCategory::where('slug', $slug)->firstOrFail(), null, null);
    }

    public function tag(Request $request, string $slug): Response
    {
        $this->ensureEnabled();

        return $this->listing($request, null, BlogTag::where('slug', $slug)->firstOrFail(), null);
    }

    public function show(Request $request, string $slug): Response
    {
        $this->ensureEnabled();

        $post = $this->blog->find($slug);
        $preview = false;

        /*
         * پیش‌نویس فقط برای سوپرادمین، و با noindex. برای بقیه ۴۰۴ — همان پاسخی
         * که برای نامکِ ناموجود می‌آید، تا وجودِ پیش‌نویس هم لو نرود.
         */
        if ($post === null) {
            $draft = BlogPost::where('slug', $slug)->first();
            abort_unless($draft && $request->user()?->isSuperAdmin(), 404);

            $post = $this->blog->preview($draft);
            $preview = true;
        }

        $response = response()->view('public.blog.show', [
            'post' => $post,
            'related' => $preview ? [] : $this->blog->related($post['id'], $post['categoryId']),
            'preview' => $preview,
            'entry' => 'resources/js/app/entries/blog.tsx',
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
        ]);

        return $preview ? $response->header('X-Robots-Tag', 'noindex, nofollow') : $response;
    }

    /**
     * تصویرِ مقاله. فقط نامِ فایلی که `store()` ساخته (۴۰ نویسه + پسوندِ تصویر)
     * و فقط از پوشه‌ی `blog/` — مسیرِ دلخواه و `..` از قاعده‌ی مسیر رد نمی‌شوند.
     */
    public function media(string $file): StreamedResponse
    {
        $path = 'blog/'.$file;
        abort_unless(PrivateFiles::disk()->exists($path), 404);

        // نامِ فایل تصادفی و تغییرناپذیر است، پس می‌شود برای همیشه کشش کرد
        return Uploads::serve($path, ['Cache-Control' => 'public, max-age=31536000, immutable']);
    }

    private function listing(Request $request, ?BlogCategory $category, ?BlogTag $tag, ?string $search): Response
    {
        $page = max(1, (int) $request->query('page', 1));
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

        return response()->view('public.blog.index', [
            'result' => $result,
            'categories' => $this->blog->categories(),
            'heading' => $heading,
            'description' => $category?->description,
            'search' => $search,
            'pageUrl' => $pageUrl,
            'entry' => 'resources/js/app/entries/blog.tsx',
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
        ]);
    }

    private function ensureEnabled(): void
    {
        abort_unless(app(FeatureFlags::class)->enabled('blog'), 404);
    }
}
