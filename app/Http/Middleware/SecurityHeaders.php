<?php

namespace App\Http\Middleware;

use App\Http\Controllers\SeoController;
use App\Support\Observability;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * هدرهای امنیتیِ پاسخ.
 *
 * ─── چرا در لاراول و نه nginx ──────────────────────────────────────────────
 * طبق قیدِ کارفرما کانفیگِ nginx دست‌کاری نمی‌شود (سرور مسیرهای دیگری را هم
 * سرو می‌کند). اینجا اعمالشان دو مزیتِ دیگر هم دارد: با کد نسخه‌بندی می‌شوند
 * و تست دارند، و با هر استقرار خودبه‌خود می‌آیند.
 *
 * ─── CSP از روی پیکربندیِ واقعی ساخته می‌شود ───────────────────────────────
 * نکته‌ی مهم: اگر CSP را ثابت می‌نوشتیم، روزی که صاحبِ پروژه شناسه‌ی GA4 را در
 * پنل وارد می‌کرد (R8)، مرورگر اسکریپتِ گوگل را **بی‌صدا بلاک** می‌کرد و
 * هیچ‌کس نمی‌فهمید چرا آمار نمی‌آید. پس دامنه‌های مجاز از همان پیکربندی
 * می‌آیند و فقط وقتی اضافه می‌شوند که آن سرویس واقعاً روشن باشد.
 */
class SecurityHeaders
{
    /** کلیدِ ذخیره‌ی nonce روی درخواست، برای استفاده در Blade. */
    public const NONCE_KEY = 'csp_nonce';

    /** مقصدهای مجازِ فرم در همین یک پاسخ — نگاه کنید به `allowFormTarget`. */
    private const FORM_TARGETS_KEY = 'csp_form_targets';

    /**
     * اجازه‌ی ارسالِ فرم به یک مقصدِ بیرونی، **فقط در همین پاسخ**.
     *
     * 🔴 نقصی که مرحلهٔ ۵ فاز ۷ پیدا کرد: صفحه‌ی انتقال به درگاه
     * (`payments/redirect.blade.php`) فرمی دارد که **مرورگر** آن را به
     * `https://bpm.shaparak.ir` یا `https://sep.shaparak.ir` می‌فرستد، ولی
     * همین صفحه `form-action 'self'` می‌گرفت. هر سه مرورگرِ اصلی این قاعده را
     * اجرا می‌کنند؛ یعنی پرداختِ آنلاینِ واقعی درست در لحظه‌ی رفتن به بانک
     * بی‌صدا متوقف می‌شد. تست‌ها نمی‌دیدند چون درگاهِ آزمایشی به همین دامنه
     * برمی‌گردد.
     *
     * ⚠️ چرا فهرستِ سراسری نه: اگر بانک‌ها در `form-action` همه‌ی صفحه‌ها
     * بودند، فرمِ تزریق‌شده در هر صفحه‌ای هم می‌توانست به آن‌ها برود. این‌جا
     * فقط همان صفحه‌ای که واقعاً به بانک می‌رود، و فقط **مبدأِ همان** آدرس،
     * اجازه می‌گیرد.
     *
     * فقط `https` پذیرفته می‌شود: درگاهی که روی http باشد پیکربندیِ غلط است
     * و بهتر است همین‌جا بایستد تا این‌که فرمِ پرداخت روی ارتباطِ باز برود.
     */
    public static function allowFormTarget(Request $request, string $url): void
    {
        $parts = parse_url($url);

        if (($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])) {
            return;
        }

        $origin = 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        $targets = (array) $request->attributes->get(self::FORM_TARGETS_KEY, []);
        $targets[] = $origin;

        $request->attributes->set(self::FORM_TARGETS_KEY, array_values(array_unique($targets)));
    }

    public function handle(Request $request, Closure $next): Response
    {
        /*
         * nonce پیش از رسیدن به view ساخته می‌شود تا Blade بتواند روی
         * `<script>`های درون‌خطی بگذاردش. بدونِ آن مجبور بودیم
         * `'unsafe-inline'` بدهیم که عملاً بخشِ بزرگی از فایده‌ی CSP را
         * از بین می‌برد.
         */
        $nonce = Str::random(24);
        $request->attributes->set(self::NONCE_KEY, $nonce);

        $response = $next($request);

        self::apply($request, $response);

        /*
         * برداشتنِ هدرهایی که نسخه‌ی نرم‌افزار را اعلام می‌کنند (R48).
         *
         * ─── چه چیزی اندازه‌گیری شد ────────────────────────────────────────
         * ⚠️ پاسخ‌ها `X-Powered-By: PHP/8.4.23` داشتند — یعنی **شماره‌ی
         * دقیقِ نسخه** به هر بازدیدکننده‌ای اعلام می‌شد.
         *
         * این به‌تنهایی آسیب‌پذیری نیست، ولی کارِ مهاجم را از «امتحان‌کردنِ
         * صدها اکسپلویت» به «جستجوی CVEهای همین نسخه» تقلیل می‌دهد. روزی
         * که یک آسیب‌پذیریِ PHP منتشر شود، اسکنرهای خودکار دقیقاً همین هدر
         * را می‌خوانند تا هدف‌های آماده را پیدا کنند.
         *
         * ⚠️ `expose_php` را در `php.ini` هم می‌شد خاموش کرد، ولی عمداً
         * اینجا انجام می‌شود: تنظیماتِ سرور طبقِ قیدِ پروژه دست‌نخورده
         * می‌ماند، و این‌طور محافظت همراهِ خودِ کد سفر می‌کند — روی هر
         * سروری که مستقر شود، بی‌آنکه کسی چیزی یادش بماند.
         */
        return $response;
    }

    /**
     * گذاشتنِ هدرها روی یک پاسخِ آماده.
     *
     * ─── 🔴 چرا این متد از `handle` بیرون آمد (R61) ────────────────────────
     * ⚠️ **پاسخی که از یک استثنا ساخته می‌شود، از «پس‌ازِ» میدل‌ورها رد
     * نمی‌شود.** اندازه‌گیری شد:
     *
     *     GET /api/v1/my-bills  (بدونِ ورود)
     *     status                 401
     *     X-Frame-Options        NULL
     *     X-Content-Type-Options NULL
     *
     * یعنی هر ۴۰۱، ۴۰۳، ۴۰۴، ۴۱۹ و ۵۰۰ — از جمله صفحه‌های **HTML**ِ خطا —
     * بدونِ هیچ هدرِ امنیتی می‌رفتند. صفحه‌ی خطای HTML بدونِ
     * `X-Frame-Options` قابلِ نشستن در iframe است.
     *
     * حالا قلابِ `respond` در `bootstrap/app.php` همین متد را روی پاسخِ
     * استثنا هم صدا می‌زند.
     */
    public static function apply(Request $request, Response $response): void
    {
        $nonce = (string) $request->attributes->get(self::NONCE_KEY, '');

        // پاسخ‌های استریمی (دانلود فایل) هدرِ محتوایی لازم ندارند
        foreach ((new self)->headers($request, $nonce) as $header => $value) {
            $response->headers->set($header, $value);
        }

        /*
         * برداشتنِ هدرهایی که نسخه‌ی نرم‌افزار را اعلام می‌کنند (R48).
         *
         * ─── چه چیزی اندازه‌گیری شد ────────────────────────────────────────
         * ⚠️ پاسخ‌ها `X-Powered-By: PHP/8.4.23` داشتند — یعنی **شماره‌ی
         * دقیقِ نسخه** به هر بازدیدکننده‌ای اعلام می‌شد.
         *
         * این به‌تنهایی آسیب‌پذیری نیست، ولی کارِ مهاجم را از «امتحان‌کردنِ
         * صدها اکسپلویت» به «جستجوی CVEهای همین نسخه» تقلیل می‌دهد.
         *
         * ⚠️ `expose_php` را در `php.ini` هم می‌شد خاموش کرد، ولی عمداً
         * اینجا انجام می‌شود: تنظیماتِ سرور طبقِ قیدِ پروژه دست‌نخورده
         * می‌ماند و محافظت همراهِ خودِ کد سفر می‌کند.
         */
        foreach (['X-Powered-By', 'Server'] as $fingerprint) {
            $response->headers->remove($fingerprint);
            header_remove($fingerprint);
        }
    }

    /**
     * @return array<string, string>
     */
    private function headers(Request $request, string $nonce): array
    {
        $headers = [
            // نوعِ اعلام‌شده‌ی فایل باید همان باشد که هست (جلوگیری از MIME sniffing)
            'X-Content-Type-Options' => 'nosniff',

            // جلوگیری از clickjacking: سایت نباید در iframe دیگری بنشیند
            'X-Frame-Options' => 'DENY',

            /*
             * آدرسِ کامل فقط به خودمان می‌رود؛ به سایتِ بیرونی فقط دامنه.
             * بدونِ این، آدرسِ صفحه‌ای مثل `/pay/۱۲۳` به هر سایتِ لینک‌شده
             * لو می‌رفت.
             */
            'Referrer-Policy' => 'strict-origin-when-cross-origin',

            /*
             * قابلیت‌هایی که این سامانه اصلاً استفاده نمی‌کند، خاموش می‌مانند؛
             * پس حتی اگر اسکریپتی تزریق شود، به دوربین و میکروفن نمی‌رسد.
             */
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',

            /*
             * جداکردنِ زمینه‌ی مرورگر از هر پنجره‌ای که ما بازش نکرده‌ایم (R48).
             *
             * ⚠️ بدونِ این، صفحه‌ای که سایتِ ما را با `window.open` باز کند
             * به `window.opener` دسترسی دارد و می‌تواند تبِ ما را به آدرسِ
             * دلخواه ببرد — حمله‌ی «tabnabbing»: کاربر برمی‌گردد و صفحه‌ی
             * ورودِ جعلی می‌بیند که از نظرِ او همان سامانه است.
             *
             * درگاهِ بانکی مسیرِ خودش را دارد و در همین تب باز می‌شود، پس
             * `same-origin` چیزی را نمی‌شکند.
             */
            'Cross-Origin-Opener-Policy' => 'same-origin',

            'Content-Security-Policy' => $this->contentSecurityPolicy(
                $nonce,
                (array) $request->attributes->get(self::FORM_TARGETS_KEY, []),
                SeoController::isIndexable($request->getPathInfo()),
            ),
        ];

        /*
         * HSTS فقط روی اتصالِ امن و بیرون از محیطِ محلی.
         *
         * فرستادنش روی HTTP بی‌معناست، و روی `localhost` خطرناک: مرورگر آن را
         * کش می‌کند و بعد از آن هیچ‌کدام از پروژه‌های محلیِ برنامه‌نویس روی
         * http باز نمی‌شوند — و پاک‌کردنش دردسر دارد.
         */
        if ($request->secure() && ! app()->environment('local', 'testing')) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        /*
         * صفحه‌های خصوصی ایندکس نمی‌شوند (مرحلهٔ ۵ · فاز ۷).
         *
         * 🔴 پیش از این پوسته‌ی داشبورد (`spa.blade.php`) هیچ noindexی نداشت و
         * robots.txt فقط `/dashboard` را می‌بست — در حالی که مسیرهای واقعی
         * `/units`، `/bills`، `/settings/complex` و ده‌ها مسیرِ دیگرند.
         *
         * ⚠️ پیش‌فرض وارونه است: **همه‌چیز** noindex است مگر آنچه صریحاً در
         * `SeoController::PUBLIC_PATHS` آمده. صفحه‌ی خصوصیِ تازه بدونِ این‌که
         * کسی یادش بماند محافظت می‌شود. هدر و نه فقط `<meta>`، چون PDFِ قبض،
         * JSONِ API و صفحه‌ی خطا `<head>` ندارند.
         */
        if (! SeoController::isIndexable($request->getPathInfo())) {
            $headers['X-Robots-Tag'] = 'noindex, nofollow';
        }

        return $headers;
    }

    /** @param  array<int, string>  $formTargets */
    private function contentSecurityPolicy(string $nonce, array $formTargets, bool $indexable): string
    {
        /*
         * خواندنِ پیکربندی **هرگز** نباید پاسخ را بشکند.
         *
         * `clientConfig()` به جدولِ `settings` می‌رسد. اگر دیتابیس در دسترس
         * نباشد (یا مهاجرت‌ها هنوز اجرا نشده باشند)، بدونِ این محافظ کلِ سایت
         * ۵۰۰ می‌داد — از جمله خودِ صفحه‌ی خطا. هدرِ امنیتی نباید به دیتابیس
         * وابسته باشد؛ در بدترین حالت سخت‌گیرانه‌ترین سیاست را می‌فرستیم.
         */
        try {
            $config = Observability::clientConfig();
        } catch (\Throwable) {
            $config = [];
        }

        $scriptSrc = ["'self'", "'nonce-{$nonce}'"];
        $connectSrc = ["'self'"];
        $imgSrc = ["'self'", 'data:', 'blob:'];
        $frameSrc = ["'self'"];

        // نقشه‌ی گوگل در فوترِ صفحه‌ی فرود درونِ iframe می‌آید
        $frameSrc[] = 'https://www.google.com';
        $frameSrc[] = 'https://maps.google.com';

        /*
         * نشانِ اینماد از `public/images/enamad.png` سرو می‌شود، نه از دامنه‌ی
         * اینماد. پس `img-src` هیچ میزبانِ بیرونیِ تازه‌ای نمی‌گیرد — لینکِ
         * کلیکِ نشان همچنان به اینماد می‌رود و آن ناوبری است، نه بارگذاریِ
         * منبع، پس CSP محدودش نمی‌کند.
         */

        if (! empty($config['ga4MeasurementId']) || ! empty($config['gtmContainerId'])) {
            $scriptSrc[] = 'https://www.googletagmanager.com';
            $connectSrc[] = 'https://www.google-analytics.com';
            $connectSrc[] = 'https://*.google-analytics.com';
            $connectSrc[] = 'https://*.analytics.google.com';
            $imgSrc[] = 'https://www.google-analytics.com';
        }

        /*
         * ⚠️ ضبطِ جلسه فقط در صفحه‌های عمومیِ بازاریابی (مرحلهٔ ۵ · فاز ۷).
         *
         * فرانت فقط در خانه/دمو/پشتیبانی Clarity را بار می‌کند؛ این‌جا همان
         * قاعده را **مرورگر** اجرا می‌کند. اگر روزی نقطه‌ی ورودی اشتباهی
         * `marketingTags` بدهد، در داشبورد اسکریپتش بلاک می‌شود و مانده‌حساب
         * و فرمِ ورود ضبط نمی‌شوند.
         */
        if (! empty($config['clarityProjectId']) && $indexable) {
            $scriptSrc[] = 'https://www.clarity.ms';
            $connectSrc[] = 'https://*.clarity.ms';
        }

        if (! empty($config['sentryDsn'])) {
            // Sentry گزارش را به زیردامنه‌ی ingest خودش می‌فرستد
            $connectSrc[] = 'https://*.ingest.sentry.io';
            $connectSrc[] = 'https://*.sentry.io';
        }

        /*
         * در حالتِ توسعه، Vite از سرورِ خودش (با WebSocket) فایل می‌دهد و
         * اسکریپت‌هایش nonce ندارند. بدونِ این استثنا `npm run dev` می‌شکند.
         */
        if (app()->environment('local')) {
            $scriptSrc[] = "'unsafe-inline'";
            $scriptSrc[] = 'http://localhost:*';
            $connectSrc[] = 'ws://localhost:*';
            $connectSrc[] = 'http://localhost:*';
        }

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $scriptSrc),
            /*
             * `'unsafe-inline'` برای استایل اجتناب‌ناپذیر است و نه سهل‌انگاری:
             * کلِ رابط از `style={{ ... }}` استفاده می‌کند تا رنگ‌ها از متغیرهای
             * CSS بیایند، و ویژگیِ style درون‌خطی **nonce نمی‌پذیرد**. تنها
             * جایگزینش بازنویسیِ همه‌ی کامپوننت‌هاست. ریسکش هم بسیار کمتر از
             * اسکریپتِ درون‌خطی است: با style نمی‌شود کد اجرا کرد.
             */
            "style-src 'self' 'unsafe-inline'",
            'img-src '.implode(' ', $imgSrc),
            "font-src 'self' data:",
            'connect-src '.implode(' ', $connectSrc),
            'frame-src '.implode(' ', $frameSrc),
            /*
             * فرم فقط به خودمان؛ و در صفحه‌ی انتقال به درگاه، به مبدأِ همان
             * بانک (`allowFormTarget`). ⚠️ آن فرم را **مرورگر** می‌فرستد، نه سرور.
             */
            'form-action '.implode(' ', ["'self'", ...$formTargets]),
            // این سایت نباید داخلِ iframe کسی برود (نسخه‌ی مدرنِ X-Frame-Options)
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);
    }
}
