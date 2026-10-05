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

        $build = function () use ($page, $category, $tag): array {
            $query = BlogPost::visible()
                ->with(['category', 'tags'])
                ->when($category, fn (Builder $q) => $q->where('category_id', $category->id))
                ->when($tag, fn (Builder $q) => $q->whereHas('tags', fn (Builder $t) => $t->whereKey($tag->id)))
                ->orderByDesc('published_at')
                ->orderByDesc('id');

            return $this->paginate($query, $page);
        };

        // ⚠️ جست‌وجو کش نمی‌شود: هر عبارتِ تازه یک کلیدِ تازه بود و کش را پر می‌کرد
        if ($term !== '') {
            return $this->search($term, $page);
        }

        return BlogCache::remember("page:{$page}:c".($category->id ?? 0).':t'.($tag->id ?? 0), $build);
    }

    /**
     * جست‌وجوی چندواژه‌ای با رتبه (مرحلهٔ ۸ · فاز ۴ · بندِ ۱۹).
     *
     * پیش از این کلِ عبارت یک «تکه» بود: «شارژ آسانسور» فقط مقاله‌ای را
     * می‌یافت که همین دو واژه را **پشتِ هم** داشت، و ترتیب فقط تاریخ بود.
     * حالا:
     *
     *   ۱. عبارت (یک‌دست‌شده) به واژه‌ها شکسته می‌شود؛ واژه‌های پرتکرار
     *      («و»، «برای»، «چطور»…) کنار می‌روند — جمله‌ی کوتاه هم کار می‌کند.
     *   ۲. مقاله باید **همه‌ی** واژه‌ها را داشته باشد — در عنوان، برچسب/دسته/
     *      خلاصه یا متن، هرجا. اگر هیچ مقاله‌ای همه را نداشت، مقاله‌هایی که
     *      **هر کدام** را دارند با نشانِ «نزدیک» (`partial`) — نه «پیدا نشد».
     *   ۳. رتبه: کلِ عبارت در عنوان ۸ و در متن ۴؛ هر واژه در عنوان ۵، در
     *      برچسب/دسته/خلاصه ۳، در متن ۱. برابرها با تاریخ و بعد شناسه.
     *
     * ⚠️ مقیاس: LIKE با `%` ِ آغازین ایندکس نمی‌خورد و کلِ مقاله‌های منتشرشده را
     * می‌پیماید — برای صدها تا چند هزار مقاله چند میلی‌ثانیه است. اگر روزی ده‌ها
     * هزار شد، جای این متد FULLTEXT با ngram است؛ امضایش عوض نمی‌شود.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, lastPage: int, partial: bool}
     */
    private function search(string $term, int $page): array
    {
        $tokens = self::searchTokens($term);
        $phrase = count($tokens) > 1 ? $term : null;

        $inAny = fn (Builder $q, string $token) => $q->where(fn (Builder $w) => $w
            ->where('search_title', 'like', "%{$token}%")
            ->orWhere('search_meta', 'like', "%{$token}%")
            ->orWhere('search_text', 'like', "%{$token}%"));

        $base = fn (bool $all) => BlogPost::visible()->where(function (Builder $q) use ($tokens, $inAny, $all): void {
            foreach ($tokens as $token) {
                $all ? $inAny($q, $token) : $q->orWhere(fn (Builder $o) => $inAny($o, $token));
            }
        });

        $partial = false;
        $query = $base(true);
        if ($query->clone()->count() === 0 && count($tokens) > 1) {
            $partial = true;
            $query = $base(false);
        }

        [$score, $bindings] = $this->relevance($tokens, $phrase);

        $query->with(['category', 'tags'])
            ->select('blog_posts.*')
            ->selectRaw("({$score}) as relevance", $bindings)
            ->orderByDesc('relevance')
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        return [...$this->paginate($query, $page), 'partial' => $partial];
    }

    /**
     * @param  list<string>  $tokens
     * @return array{string, list<string>}
     */
    private function relevance(array $tokens, ?string $phrase): array
    {
        $parts = [];
        $bindings = [];
        $weigh = function (string $column, string $needle, int $weight) use (&$parts, &$bindings): void {
            $parts[] = "CASE WHEN {$column} LIKE ? THEN {$weight} ELSE 0 END";
            $bindings[] = "%{$needle}%";
        };

        if ($phrase !== null) {
            $weigh('search_title', $phrase, 8);
            $weigh('search_text', $phrase, 4);
        }
        foreach ($tokens as $token) {
            $weigh('search_title', $token, 5);
            $weigh('search_meta', $token, 3);
            $weigh('search_text', $token, 1);
        }

        return [implode(' + ', $parts), $bindings];
    }

    /** واژه‌هایی که به جست‌وجو چیزی اضافه نمی‌کنند */
    private const SEARCH_STOPWORDS = [
        'و', 'در', 'به', 'از', 'که', 'را', 'با', 'این', 'آن', 'برای', 'تا', 'یا', 'هم', 'چه', 'چطور',
        'چگونه', 'چرا', 'کنیم', 'کنم', 'کنید', 'است', 'هست', 'می', 'ها', 'های', 'یک', 'هر', 'بر', 'اگر',
    ];

    /**
     * واژه‌های جست‌وجو از عبارتِ یک‌دست‌شده — حداکثر شش تا. اگر همه پرتکرار
     * بودند (مثلاً «برای چه»)، خودِ عبارت یک واژه حساب می‌شود.
     *
     * @return list<string>
     */
    public static function searchTokens(string $term): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_unique(array_filter(
            $words,
            fn (string $w) => mb_strlen($w) >= 2 && ! in_array($w, self::SEARCH_STOPWORDS, true),
        )));

        return array_slice($tokens !== [] ? $tokens : [$term], 0, 6);
    }

    /**
     * @param  Builder<BlogPost>  $query
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, lastPage: int}
     */
    private function paginate(Builder $query, int $page): array
    {
        $total = $query->clone()->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));

        return [
            'items' => $query->forPage(min($page, $lastPage), self::PER_PAGE)->get()
                ->map(fn (BlogPost $post) => $this->card($post))->all(),
            'total' => $total,
            'page' => min($page, $lastPage),
            'lastPage' => $lastPage,
        ];
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

    /*
     * تاریخچه: مرحلهٔ ۷ · فاز ۷ امتیاز را از موضوع گرفت (نه تازگی) ولی وقتی
     * چیزی مرتبط نبود تازه‌ترین‌ها را «مرتبط» می‌نامید؛ مرحلهٔ ۸ · فاز ۳ آن را
     * بست. امتیاز در PHP است نه SQL: صدها مقاله، و نتیجه کش می‌شود.
     */
    /**
     * مقاله‌های **واقعاً** مرتبط (مرحلهٔ ۸ · فاز ۳ · بندِ ۲۰).
     *
     * امتیاز از داده‌ی خودِ مقاله: ۳ برای هر برچسبِ مشترک، ۲ برای همان دسته، و
     * ۱ برای هر کلیدواژه‌ی مشترکِ عنوان (تا ۲). فقط امتیازِ مثبت — پیش از این
     * وقتی چیزی مرتبط نبود، تازه‌ترین‌ها با برچسبِ «مرتبط» پر می‌شدند. برابرها
     * با تاریخ و بعد شناسه، پس ترتیب بینِ درخواست‌ها ثابت است. اگر هیچ نبود،
     * `latest()` با عنوانِ دیگری جایش می‌آید (`BlogPages`).
     *
     * @return list<array<string, mixed>>
     */
    public function related(int $postId, ?int $categoryId, int $limit = 3): array
    {
        return BlogCache::remember("related:{$postId}", function () use ($postId, $categoryId, $limit): array {
            $current = BlogPost::with('tags')->find($postId);
            $tagIds = $current?->tags->pluck('id')->all() ?? [];
            $keywords = self::keywords((string) $current?->title);

            return BlogPost::visible()
                ->with(['category', 'tags'])
                ->whereKeyNot($postId)
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (BlogPost $post) => [
                    'post' => $post,
                    'score' => 3 * $post->tags->whereIn('id', $tagIds)->count()
                        + ($categoryId !== null && $post->category_id === $categoryId ? 2 : 0)
                        + min(2, count(array_intersect(self::keywords($post->title), $keywords))),
                ])
                ->filter(fn (array $row) => $row['score'] > 0)
                ->sortByDesc('score') // پایدار است: برابرها همان ترتیبِ تازگی و شناسه را نگه می‌دارند
                ->take($limit)
                ->map(fn (array $row) => $this->card($row['post']))
                ->values()
                ->all();
        });
    }

    /**
     * تازه‌ترین مقاله‌ها جز این یکی — جای «مرتبط» وقتی هیچ مقاله‌ی مرتبطی نیست.
     *
     * @return list<array<string, mixed>>
     */
    public function latest(int $exceptId, int $limit = 3): array
    {
        return BlogCache::remember("latest-except:{$exceptId}", fn (): array => BlogPost::visible()
            ->with('category')
            ->whereKeyNot($exceptId)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (BlogPost $post) => $this->card($post))
            ->all());
    }

    /** واژه‌های معنادارِ عنوان: نرمال، سه‌حرف به بالا، بی‌واژه‌های پرتکرار */
    private const STOPWORDS = ['برای', 'بدون', 'چطور', 'چرا', 'کنیم', 'کنید', 'است', 'آیا', 'کجا', 'نباید', 'باید', 'دارد', 'همه', 'واقعا'];

    /** @return list<string> */
    public static function keywords(string $title): array
    {
        $words = preg_split('/[^\p{L}\p{N}\x{200C}]+/u', PersianText::normalize($title)) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            fn (string $w) => mb_strlen($w) >= 3 && ! in_array($w, self::STOPWORDS, true),
        )));
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
            ->orderByDesc('id')
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

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        // سرِ مرزِ واژه، نه وسطِ آن
        $cut = mb_substr($text, 0, $limit);
        $space = mb_strrpos($cut, ' ');

        $cut = $space !== false && $space > $limit * 0.6 ? mb_substr($cut, 0, $space) : $cut;

        /*
         * ⚠️ نه `rtrim($cut, '،؛…')`: rtrim بایت‌به‌بایت کار می‌کند و بایت‌های
         * «،» و «؛» را از تهِ حرف‌های فارسیِ دیگر هم می‌کَند — UTF-8 ِ شکسته که
         * JSON ِ API را با «Malformed UTF-8» می‌انداخت (E2E گرفتش).
         */
        return (string) preg_replace('/[\s،,.؛:]+$/u', '', $cut).'…';
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
            // خلاصه تا ۴۰۰ نویسه است و موتورِ جست‌وجو حدودِ ۱۶۰ را نشان می‌دهد (مرحلهٔ ۸ · فاز ۴)
            'metaDescription' => $post->meta_description ?: $this->summary($post->excerpt ?: $post->body_html, 160),
            'canonical' => CanonicalUrl::base().'/blog/'.rawurlencode($post->slug),
        ];
    }
}
