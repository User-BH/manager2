<?php

namespace Tests\Feature;

use App\Support\CanonicalUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * سئوی فنی و پیش‌نمایشِ پیام‌رسان‌ها (R38).
 *
 * ─── چرا این‌ها تست دارند ───────────────────────────────────────────────────
 * خرابیِ سئو هیچ خطایی نمی‌دهد و ماه‌ها بعد در افتِ رتبه دیده می‌شود. تگِ
 * `og:image`ِ نسبی فقط یعنی پیش‌نمایشِ تلگرام خالی می‌آید، و `canonical`ِ
 * اشتباه یعنی گوگل صفحه‌ی دیگری را معتبر می‌داند — هیچ‌کدام در لاگ نمی‌آیند.
 */
class SeoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int,array{0:string}>
     */
    public static function indexablePages(): array
    {
        return [['/'], ['/demo'], ['/support']];
    }

    /**
     * ⚠️ آدرسِ متعارف هرگز از هدرِ `Host` ساخته نمی‌شود.
     *
     * پیش از R38 پوسته `url()->current()` می‌نوشت و این آزمونِ واقعی نتیجه‌اش
     * را نشان داد:
     *
     *     curl -H "Host: evil.example.com" … → canonical="http://evil.example.com"
     *
     * یعنی مهاجم می‌توانست کاری کند که موتورِ جستجو محتوای ما را به دامنه‌ی
     * او نسبت بدهد، و `og:url`ِ دستکاری‌شده در پیام‌رسان‌ها لینکِ دیگری نشان
     * بدهد.
     */
    public function test_the_canonical_url_ignores_a_forged_host_header(): void
    {
        /*
         * ⚠️ `$this->withHeaders(['Host' => …])` این حمله را شبیه‌سازی
         * **نمی‌کند**.
         *
         * نسخه‌ی اولِ همین تست همان را می‌زد و در پاسِ خرابکاری پوچ درآمد:
         * با برگرداندنِ `url()->current()` هم سبز ماند. اندازه‌گیری نشان داد
         * کلاینتِ تستِ لاراول میزبان را از خودِ URI می‌گیرد و هدرِ `Host`ِ
         * دستی را نادیده می‌گیرد — پس هرگز میزبانِ جعلی ساخته نمی‌شد.
         *
         * `Request::create()` با آدرسِ کامل واقعاً میزبان را عوض می‌کند
         * (راستی‌آزمایی شد: `getHost()` برابرِ `evil.example.com` شد).
         */
        $forged = Request::create('http://evil.example.com/demo');

        $this->assertSame('evil.example.com', $forged->getHost(), 'خودِ ابزارِ تست میزبان را عوض نکرد.');

        $this->assertSame(
            CanonicalUrl::base().'/demo',
            CanonicalUrl::forRequest($forged),
            'آدرسِ متعارف از هدرِ درخواست ساخته شده است.',
        );
    }

    /**
     * ⚠️ پوسته نباید هیچ آدرسی را از خودِ درخواست بسازد.
     *
     * تستِ بالا رفتارِ `CanonicalUrl` را می‌سنجد، ولی اگر کسی دوباره در
     * Blade مستقیم `url()->current()` بنویسد، آن کلاس دور زده می‌شود و
     * آسیب‌پذیری برمی‌گردد بی‌آنکه تستِ بالا بفهمد.
     */
    public function test_the_public_shell_never_derives_urls_from_the_request(): void
    {
        $source = (string) file_get_contents(resource_path('views/layouts/public.blade.php'));

        /*
         * ⚠️ کامنت‌ها اول برداشته می‌شوند.
         *
         * همان تله‌ای که در R37 هم خوردم: تستی که رشته را در سورس می‌گردد،
         * توضیحِ بالای کد را هم می‌بیند. خودِ همین فایل در کامنتش
         * می‌نویسد «پیش از این `url()->current()` بود…» و بدونِ این پاک‌سازی
         * تست روی کدِ کاملاً درست هم قرمز می‌شد.
         */
        $code = preg_replace(['/\{\{--.*?--\}\}/s', '/\/\*.*?\*\//s'], '', $source) ?? $source;

        foreach (['url()->current()', 'url()->full()', 'request()->url()', 'Request::url('] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $code,
                "پوسته‌ی عمومی «{$forbidden}» دارد؛ میزبان از هدرِ درخواست می‌آید.",
            );
        }
    }

    /** کوئری‌استرینگ نباید در آدرسِ متعارف بیاید. */
    public function test_the_canonical_url_drops_query_strings(): void
    {
        /*
         * `?source=pwa` (میان‌برهای R35) و `?utm_*` همان صفحه‌اند. اگر در
         * canonical بیایند، موتورِ جستجو هر کدام را صفحه‌ی جداگانه می‌بیند و
         * اعتبارِ صفحه بین نسخه‌های تکراری خرد می‌شود.
         */
        $request = Request::create('http://localhost/demo?source=pwa&utm_source=telegram');

        $this->assertSame(CanonicalUrl::base().'/demo', CanonicalUrl::forRequest($request));
    }

    /**
     * `og:url` و `canonical` باید **یکی** باشند.
     *
     * اگر فرق کنند، پیام‌رسان لینکی نشان می‌دهد که با آنچه گوگل معتبر
     * می‌داند نمی‌خواند و اعتبارِ صفحه بین دو آدرس خرد می‌شود.
     */
    #[DataProvider('indexablePages')]
    public function test_canonical_and_og_url_agree(string $url): void
    {
        $html = (string) $this->get($url)->getContent();

        preg_match('/<link[^>]+rel="canonical"[^>]+href="([^"]+)"/', $html, $canonical);
        preg_match('/<meta[^>]+property="og:url"[^>]+content="([^"]+)"/', $html, $ogUrl);

        $this->assertSame($canonical[1], $ogUrl[1], "«{$url}»: canonical و og:url یکی نیستند.");
    }

    /**
     * ⚠️ پیش‌نمایشِ پیام‌رسان‌ها (فنی-۲۱).
     *
     * تلگرام و واتساپ و روبیکا همگی Open Graph می‌خوانند ولی سخت‌گیرند:
     * آدرسِ تصویر باید **مطلق** باشد (نسبی را نمی‌فهمند) و ابعاد باید
     * اعلام شود، وگرنه باید خودِ فایل را دانلود کنند تا اندازه را بفهمند و
     * اگر کند بود پیش‌نمایش را رها می‌کنند.
     *
     * @dataProvider indexablePages
     */
    #[DataProvider('indexablePages')]
    public function test_messenger_preview_tags_are_complete(string $url): void
    {
        $html = (string) $this->get($url)->getContent();

        foreach ([
            'og:title', 'og:description', 'og:type', 'og:site_name', 'og:locale',
            'og:image', 'og:image:secure_url', 'og:image:type',
            'og:image:width', 'og:image:height', 'og:image:alt',
        ] as $property) {
            $this->assertMatchesRegularExpression(
                '/<meta[^>]+property="'.preg_quote($property, '/').'"[^>]+content="[^"]+"/',
                $html,
                "«{$url}» تگِ «{$property}» ندارد.",
            );
        }

        preg_match('/<meta[^>]+property="og:image"[^>]+content="([^"]+)"/', $html, $image);

        $this->assertStringStartsWith('http', $image[1], 'آدرسِ og:image باید مطلق باشد.');
    }

    /**
     * تصویرِ پیش‌نمایش باید واقعاً موجود و به‌قدرِ کافی سبک باشد.
     *
     * ⚠️ واتساپ تصویرِ بزرگ‌تر از حدودِ ‎۳۰۰KB را رد می‌کند و هیچ خطایی هم
     * نمی‌دهد — فقط پیش‌نمایش خالی می‌آید.
     */
    public function test_the_preview_image_exists_and_stays_small(): void
    {
        $path = public_path('images/og-cover.png');

        $this->assertFileExists($path);

        $size = getimagesize($path);

        $this->assertSame([1200, 630], [$size[0], $size[1]], 'ابعادِ اعلام‌شده با فایل نمی‌خواند.');
        $this->assertLessThan(
            300 * 1024,
            filesize($path),
            'تصویرِ پیش‌نمایش از سقفِ واتساپ گذشته؛ پیش‌نمایش بی‌صدا خالی می‌شود.',
        );
    }

    /**
     * مسیرِ راهنما: JSON-LD و نوارِ دیدنی باید **با هم بخوانند**.
     *
     * ⚠️ داده‌ی ساخت‌یافته‌ای که چیزی بگوید که روی صفحه نیست، از دیدِ گوگل
     * تخلف است و کلِ نتیجه‌ی غنی حذف می‌شود.
     */
    public function test_breadcrumbs_match_between_markup_and_structured_data(): void
    {
        foreach (['/demo' => 'دمو', '/support' => 'پشتیبانی و راهنما'] as $url => $label) {
            $html = (string) $this->get($url)->getContent();

            // نوارِ دیدنی
            $this->assertStringContainsString('aria-label="مسیر راهنما"', $html);
            $this->assertStringContainsString($label, $html);

            // داده‌ی ساخت‌یافته
            $this->assertStringContainsString('BreadcrumbList', $html);

            preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $blocks);

            $breadcrumb = null;

            foreach ($blocks[1] as $block) {
                $decoded = json_decode(html_entity_decode($block), true);

                foreach ($decoded['@graph'] ?? [] as $node) {
                    if (($node['@type'] ?? null) === 'BreadcrumbList') {
                        $breadcrumb = $node;
                    }
                }
            }

            $this->assertNotNull($breadcrumb, "«{$url}» داده‌ی BreadcrumbList ندارد.");
            $this->assertCount(2, $breadcrumb['itemListElement']);
            $this->assertSame('خانه', $breadcrumb['itemListElement'][0]['name']);
            $this->assertSame($label, $breadcrumb['itemListElement'][1]['name']);

            // ⚠️ آدرسِ آخرین حلقه باید دقیقاً همان canonical باشد
            preg_match('/<link[^>]+rel="canonical"[^>]+href="([^"]+)"/', $html, $canonical);
            $this->assertSame($canonical[1], $breadcrumb['itemListElement'][1]['item']);
        }
    }

    /**
     * صفحه‌ی خانه ریشه است و مسیرِ تک‌عضوی نباید بگیرد.
     */
    public function test_the_home_page_has_no_breadcrumb(): void
    {
        $html = (string) $this->get('/')->getContent();

        $this->assertStringNotContainsString('BreadcrumbList', $html);
        $this->assertStringNotContainsString('aria-label="مسیر راهنما"', $html);
    }

    /**
     * صفحه‌ی ورود نباید ایندکس شود.
     *
     * ⚠️ فرمِ ورود در نتایج جستجو نه فایده‌ای دارد نه باید داشته باشد؛ و
     * اگر ایندکس شود، اعتبارِ صفحه‌ی فرود را هم می‌گیرد.
     */
    public function test_the_auth_page_is_not_indexable(): void
    {
        $this->get('/auth')->assertSee('name="robots" content="noindex,follow"', false);
    }

    /**
     * طولِ عنوان و توضیح در محدوده‌ای که گوگل نمی‌بُرد.
     *
     * @dataProvider indexablePages
     */
    #[DataProvider('indexablePages')]
    public function test_title_and_description_fit_in_search_results(string $url): void
    {
        $html = (string) $this->get($url)->getContent();

        preg_match('/<title>(.*?)<\/title>/s', $html, $title);
        preg_match('/<meta[^>]+name="description"[^>]+content="([^"]+)"/', $html, $description);

        $titleLength = mb_strlen(html_entity_decode($title[1]));
        $descriptionLength = mb_strlen(html_entity_decode($description[1]));

        $this->assertGreaterThan(20, $titleLength, "عنوانِ «{$url}» خیلی کوتاه است.");
        $this->assertLessThanOrEqual(60, $titleLength, "عنوانِ «{$url}» در نتایج بریده می‌شود.");

        $this->assertGreaterThan(70, $descriptionLength, "توضیحِ «{$url}» خیلی کوتاه است.");
        $this->assertLessThanOrEqual(160, $descriptionLength, "توضیحِ «{$url}» بریده می‌شود.");
    }

    /**
     * نقشه‌ی سایت باید همان آدرس‌هایی را بدهد که واقعاً کار می‌کنند.
     *
     * ⚠️ آدرسِ ۴۰۴ در sitemap اعتمادِ خزنده را کم می‌کند و بقیه‌ی آدرس‌ها را
     * هم دیرتر می‌بیند.
     */
    public function test_every_sitemap_url_responds(): void
    {
        $xml = (string) $this->get('/sitemap.xml')->getContent();

        preg_match_all('/<loc>([^<]+)<\/loc>/', $xml, $locations);

        $this->assertNotEmpty($locations[1], 'نقشه‌ی سایت خالی است.');

        foreach ($locations[1] as $location) {
            $path = parse_url($location, PHP_URL_PATH) ?: '/';

            $response = $this->get($path);

            $response->assertSuccessful();

            /*
             * ⚠️ «۲۰۰ گرفت» به‌تنهایی هیچ چیزی را ثابت نمی‌کند.
             *
             * روتِ catch-allِ SPA تقریباً **هر** مسیری را با پوسته‌ی داشبورد
             * و کدِ ۲۰۰ جواب می‌دهد. در پاسِ خرابکاری معلوم شد: ریشه‌ی نقشه
             * را به `…/nope` عوض کردم، `/nope/demo` هم ۲۰۰ داد و تست سبز
             * ماند — یعنی نقشه‌ای پر از آدرسِ بی‌معنا از کنارش رد می‌شد.
             *
             * سنجه‌ی درست این است که صفحه‌ی مقصد **خودش را همان آدرس
             * بداند**: canonicalش باید دقیقاً همان `<loc>` باشد. پوسته‌ی
             * catch-all اصلاً canonical ندارد، پس این شرط فوراً می‌شکند.
             */
            preg_match('/<link[^>]+rel="canonical"[^>]+href="([^"]+)"/', (string) $response->getContent(), $canonical);

            $this->assertNotEmpty($canonical, "صفحه‌ی «{$path}» از نقشه‌ی سایت اصلاً canonical ندارد.");
            $this->assertSame(
                rtrim($location, '/') ?: $location,
                rtrim($canonical[1], '/') ?: $canonical[1],
                "آدرسِ نقشه با canonicalِ خودِ صفحه‌ی «{$path}» نمی‌خواند.",
            );
        }
    }
}
