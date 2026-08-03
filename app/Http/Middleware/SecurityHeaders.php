<?php

namespace App\Http\Middleware;

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

        // پاسخ‌های استریمی (دانلود فایل) هدرِ محتوایی لازم ندارند
        foreach ($this->headers($request, $nonce) as $header => $value) {
            $response->headers->set($header, $value);
        }

        return $response;
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

            'Content-Security-Policy' => $this->contentSecurityPolicy($nonce),
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

        return $headers;
    }

    private function contentSecurityPolicy(string $nonce): string
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
         * نشانِ اعتمادِ الکترونیکی (اینماد) در فوتر.
         *
         * تصویرش از دامنه‌ی خودِ اینماد سرو می‌شود و قابلِ میزبانیِ محلی هم
         * نیست: نشان باید **زنده** از سرورِ اینماد بیاید تا اعتبارِ لحظه‌ای‌اش
         * قابلِ بررسی بماند. پس فقط همین یک دامنه به `img-src` اضافه می‌شود،
         * نه یک اجازه‌ی کلی.
         */
        $imgSrc[] = 'https://trustseal.enamad.ir';

        if (! empty($config['ga4MeasurementId']) || ! empty($config['gtmContainerId'])) {
            $scriptSrc[] = 'https://www.googletagmanager.com';
            $connectSrc[] = 'https://www.google-analytics.com';
            $connectSrc[] = 'https://*.google-analytics.com';
            $connectSrc[] = 'https://*.analytics.google.com';
            $imgSrc[] = 'https://www.google-analytics.com';
        }

        if (! empty($config['clarityProjectId'])) {
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
            // فرم فقط به خودمان یا درگاه بانکی (که با POSTِ سمتِ سرور می‌رود)
            "form-action 'self'",
            // این سایت نباید داخلِ iframe کسی برود (نسخه‌ی مدرنِ X-Frame-Options)
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);
    }
}
