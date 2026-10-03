<?php

namespace App\Http\Controllers;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Services\Blog\BlogPages;
use App\Services\Blog\PublicBlog;
use App\Services\Features\FeatureFlags;
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
    public function __construct(
        private readonly PublicBlog $blog,
        private readonly BlogPages $pages,
    ) {}

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

        $page = $this->pages->article($post, $preview);
        $response = response()->view('public.blog.show', [
            ...$page['view'],
            'entry' => 'resources/js/app/entries/blog.tsx',
            'seo' => $page['seo'],
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
        $page = $this->pages->listing(max(1, (int) $request->query('page', 1)), $category, $tag, $search);

        return response()->view('public.blog.index', [
            ...$page['view'],
            'entry' => 'resources/js/app/entries/blog.tsx',
            'seo' => $page['seo'],
        ]);
    }

    private function ensureEnabled(): void
    {
        abort_unless(app(FeatureFlags::class)->enabled('blog'), 404);
    }
}
