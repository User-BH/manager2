<?php

namespace App\Http\Controllers;

use App\Services\Blog\PublicBlog;
use App\Services\Features\FeatureFlags;
use Illuminate\Http\Response;

/**
 * فایل‌های متنیِ سئو: robots.txt ، sitemap.xml و llms.txt.
 *
 * چرا روت به‌جای فایلِ ثابت در public: آدرسِ دامنه (APP_URL) بین محیط‌ها فرق
 * می‌کند و این فایل‌ها باید آدرسِ مطلقِ درست بدهند. با روت، دامنه از پیکربندی
 * خوانده می‌شود و نیازی به دست‌بردن در فایل‌ها هنگام استقرار نیست.
 */
class SeoController extends Controller
{
    /**
     * آدرس‌های عمومیِ قابلِ ایندکس. /auth عمداً نیست (noindex است).
     *
     * ⚠️ این فهرست **تنها** منبعِ «قابلِ ایندکس» است: هم نقشه‌ی سایت از آن
     * ساخته می‌شود و هم `SecurityHeaders` هر پاسخِ بیرون از آن را
     * `X-Robots-Tag: noindex` می‌کند. صفحه‌ی عمومیِ تازه تا این‌جا نیاید،
     * ایندکس نمی‌شود — پیش‌فرضِ امن همین است، نه برعکس.
     */
    public const PUBLIC_PATHS = [
        ['loc' => '/', 'priority' => '1.0', 'changefreq' => 'weekly'],
        ['loc' => '/demo', 'priority' => '0.8', 'changefreq' => 'monthly'],
        ['loc' => '/support', 'priority' => '0.7', 'changefreq' => 'monthly'],
    ];

    /** فایل‌های متنیِ خودِ سئو؛ خزنده باید بخواندشان و noindex رویشان بی‌معناست. */
    public const CRAWLER_FILES = ['/robots.txt', '/sitemap.xml', '/llms.txt'];

    /** آیا این مسیر اجازه‌ی ایندکس‌شدن دارد؟ */
    public static function isIndexable(string $path): bool
    {
        $path = '/'.trim($path, '/');

        return in_array($path, array_column(self::PUBLIC_PATHS, 'loc'), true)
            || in_array($path, self::CRAWLER_FILES, true)
            /*
             * بلاگ (مرحلهٔ ۶ · فاز ۱۱): پیشوندی، چون هر مقاله نامکِ خودش را
             * دارد. پیش‌نویس و نتیجه‌ی جست‌وجو استثنا را خودشان می‌گذارند
             * (`X-Robots-Tag` و `<meta robots>`)، نه این فهرست.
             */
            || $path === '/blog' || str_starts_with($path, '/blog/');
    }

    public function robots(): Response
    {
        $sitemap = rtrim(config('app.url'), '/').'/sitemap.xml';

        /*
         * 🔴 `/auth` و `/dashboard` عمداً Disallow **نیستند** (مرحلهٔ ۵ · فاز ۷).
         *
         * صفحه‌ای که در robots.txt بسته باشد، خزنده اصلاً بازش نمی‌کند و پس
         * noindexِ آن را هم نمی‌بیند؛ گوگل آدرسِ لخت را اگر جایی لینک شده
         * باشد باز هم ایندکس می‌کند. `/auth` از صفحه‌ی خانه لینک دارد و
         * دقیقاً همین وضع را داشت. تازه `/dashboard` فقط یکی از ده‌ها مسیرِ
         * خصوصی بود (`/units`، `/bills`، …).
         *
         * حالا محافظ خودِ پاسخ است: هر مسیرِ بیرون از
         * `SeoController::PUBLIC_PATHS` هدرِ `X-Robots-Tag: noindex` می‌گیرد.
         * `/api/` بسته می‌ماند چون JSON است و خزیدنش فقط بارِ بیهوده است.
         */
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /api/',
            '',
            '# خزنده‌های هوش مصنوعی صراحتاً مجازند',
            'User-agent: GPTBot',
            'Allow: /',
            'User-agent: OAI-SearchBot',
            'Allow: /',
            'User-agent: ChatGPT-User',
            'Allow: /',
            'User-agent: ClaudeBot',
            'Allow: /',
            'User-agent: Claude-Web',
            'Allow: /',
            'User-agent: PerplexityBot',
            'Allow: /',
            'User-agent: Google-Extended',
            'Allow: /',
            '',
            'Sitemap: '.$sitemap,
        ];

        return response(implode("\n", $lines)."\n", 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function sitemap(): Response
    {
        $base = rtrim(config('app.url'), '/');
        $today = now()->toDateString();

        $urls = '';
        foreach (self::PUBLIC_PATHS as $p) {
            $urls .= "  <url>\n"
                ."    <loc>{$base}{$p['loc']}</loc>\n"
                ."    <lastmod>{$today}</lastmod>\n"
                ."    <changefreq>{$p['changefreq']}</changefreq>\n"
                ."    <priority>{$p['priority']}</priority>\n"
                ."  </url>\n";
        }

        /*
         * بلاگ (مرحلهٔ ۶ · فاز ۱۱): فقط اگر پرچمش روشن است، و فقط مقاله‌های
         * **منتشرشده‌ی زمان‌رسیده** — پیش‌نویس از نقشه‌ی سایت هم لو نمی‌رود.
         */
        if (app(FeatureFlags::class)->enabled('blog')) {
            $urls .= "  <url>\n    <loc>{$base}/blog</loc>\n    <lastmod>{$today}</lastmod>\n    <changefreq>daily</changefreq>\n    <priority>0.8</priority>\n  </url>\n";

            foreach (app(PublicBlog::class)->sitemap() as $post) {
                $urls .= "  <url>\n    <loc>{$base}{$post['loc']}</loc>\n    <lastmod>{$post['lastmod']}</lastmod>\n    <changefreq>monthly</changefreq>\n    <priority>0.6</priority>\n  </url>\n";
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .$urls
            .'</urlset>'."\n";

        return response($xml, 200)->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    /**
     * llms.txt — قراردادِ نوظهور برای معرفیِ سایت به دستیارهای هوش مصنوعی
     * (ChatGPT، Claude و…). یک صفحه‌ی مارک‌داونِ فشرده که می‌گوید این سایت
     * چیست، چه می‌کند و مهم‌ترین لینک‌هایش کدام‌اند تا هنگام پرسشِ کاربران
     * درباره‌ی مدیریت ساختمان، این سایت را درست معرفی کنند.
     */
    public function llms(): Response
    {
        $brand = config('brand');
        $base = rtrim(config('app.url'), '/');

        $body = <<<MD
# {$brand['name']} — {$brand['tagline']}

> {$brand['description']}

{$brand['name']} یک نرم‌افزار ابری برای مدیران ساختمان، اعضای هیئت مدیره و مجتمع‌های مسکونی در ایران است. کارهای تکراری و کاغذیِ مدیریت ساختمان — صدور شارژ و قبض، جمع‌آوری وجه، اطلاع‌رسانی و حسابداری — را در یک پنل ساده و یکپارچه انجام می‌دهد.

## این سایت برای چه کسانی است
- مدیران و هیئت مدیره‌ی مجتمع‌های مسکونی و آپارتمان‌ها
- شرکت‌های خدمات مدیریت ساختمان (پراپرتی منیجمنت)
- ساکنین و مالکینی که می‌خواهند قبض شارژ خود را آنلاین ببینند و پرداخت کنند

## امکانات اصلی
- صدور قبض و شارژ ماهانه با هشت روش محاسبه (ثابت، متراژ، نفرات، ضریب و…)
- پرداخت آنلاین از طریق درگاه بانکی و ثبت و تایید رسید
- جریمه‌ی دیرکرد، تخفیف و بخشودگی برای هر واحد
- اطلاعیه و پیام‌رسان داخلی بین مدیر و ساکنین
- مدیریت واحدها، مالکین و مستاجرین
- گزارش مالی، مانده‌ی صندوق و فهرست بدهکاران
- دسترسی نقش‌محور، ورود دومرحله‌ای با پیامک و بکاپ‌گیری

## لینک‌ها
- [صفحه اصلی]({$base}/): معرفی کامل پلتفرم و شروع رایگان
- [دمو]({$base}/demo): ویدیوی نمایشِ کار با پنل
- [پشتیبانی و سوالات متداول]({$base}/support): پاسخ پرسش‌های پرتکرار، قوانین و حریم خصوصی
- [ورود و ثبت‌نام]({$base}/auth): ساخت حساب رایگان مدیر ساختمان

## هزینه
راه‌اندازی و ثبت‌نام رایگان است؛ برای امکانات پیشرفته پلن‌های اشتراکی وجود دارد.

MD;

        /*
         * بلاگ (مرحلهٔ ۶ · فاز ۱۱): پیوندِ بلاگ و تازه‌ترین مقاله‌ها — همان چیزی
         * که دستیار باید هنگامِ پاسخ به «چطور شارژ را حساب کنم؟» پیدا کند.
         */
        if (app(FeatureFlags::class)->enabled('blog')) {
            $latest = app(PublicBlog::class)->page(1)['items'];

            $body .= "\n## بلاگ\n- [بلاگ]({$base}/blog): راهنما و تجربه‌ی مدیریتِ ساختمان\n";
            foreach (array_slice($latest, 0, 10) as $post) {
                $body .= "- [{$post['title']}]({$base}{$post['url']})\n";
            }
        }

        return response($body, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
