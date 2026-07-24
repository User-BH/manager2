<?php

namespace App\Http\Controllers;

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
    /** آدرس‌های عمومیِ قابلِ ایندکس. /auth عمداً نیست (noindex است). */
    private const PUBLIC_PATHS = [
        ['loc' => '/', 'priority' => '1.0', 'changefreq' => 'weekly'],
        ['loc' => '/demo', 'priority' => '0.8', 'changefreq' => 'monthly'],
        ['loc' => '/support', 'priority' => '0.7', 'changefreq' => 'monthly'],
    ];

    public function robots(): Response
    {
        $sitemap = rtrim(config('app.url'), '/').'/sitemap.xml';

        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /api/',
            'Disallow: /auth',
            'Disallow: /dashboard',
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

        return response($body, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
