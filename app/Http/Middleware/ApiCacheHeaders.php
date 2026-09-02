<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * «این پاسخ را هیچ‌جا ذخیره نکن» (R60).
 *
 * ─── 🔴 شکافی که اندازه‌گیری شد ────────────────────────────────────────────
 * ⚠️ **هیچ پاسخِ APIی این سامانه هدرِ کش نداشت.** نه `no-store`، نه
 * `private`، هیچ. یعنی موجودیِ کیفِ پول و فهرستِ قبض‌های یک ساکن می‌توانست
 * در این‌ها بنشیند:
 *
 *   • کشِ دیسکیِ خودِ مرورگر
 *   • هر واسطی که در مسیر باشد
 *   • و — که بدترش بود — service workerِ خودمان
 *
 * ─── چرا این لایه، در حالی که service worker هم اصلاح شد ───────────────────
 * ⚠️ چون این دو **مستقل**اند و باید باشند.
 *
 * جدولِ `POLICY` در `sw.js` تصمیم می‌گیرد چه چیزی کش شود. اگر روزی آن جدول
 * اشتباه شود — یا مرورگری SW را نادیده بگیرد، یا کاربر SW را غیرفعال کند —
 * این هدر همچنان سرِ جایش است.
 *
 * محافظتی که فقط یک لایه دارد، محافظت نیست؛ یک فرض است.
 *
 * ─── منعِ پیش‌فرض ──────────────────────────────────────────────────────────
 * هر مسیرِ `/api/` که در `config('pwa.public_api')` نباشد `no-store`
 * می‌گیرد. مسیرِ تازه‌ای که فردا اضافه شود خودبه‌خود امن است — نه اینکه
 * یادِ کسی بماند مستثنایش کند.
 */
class ApiCacheHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        self::apply($request, $response);

        return $response;
    }

    /**
     * ⚠️ جدا از `handle` تا قلابِ `respond` هم بتواند صدایش بزند: پاسخی
     * که از استثنا ساخته می‌شود از «پس‌ازِ» میدل‌ورها رد نمی‌شود، و ۴۰۱ی
     * که `no-store` نداشته باشد همان نقصِ R60 است با درِ دیگر.
     */
    public static function apply(Request $request, Response $response): void
    {
        if (! $request->is('api/*')) {
            return;
        }

        if (self::isPublic($request)) {
            /*
             * ⚠️ `public` و نه `private`: این پاسخ برای همه یکسان است و
             * اجازه دارد در هر کشی بنشیند. شرطِ عضویت در آن فهرست هم دقیقاً
             * همین است.
             */
            $response->headers->set(
                'Cache-Control',
                'public, max-age='.max(0, (int) config('pwa.public_api_ttl', 300)),
            );

            return;
        }

        /*
         * `no-store` و نه `no-cache`.
         *
         * ⚠️ تفاوتشان دقیقاً همان چیزی است که اینجا اهمیت دارد: `no-cache`
         * یعنی «ذخیره کن ولی پیش از استفاده اعتبارش را بپرس» — پاسخ روی
         * دیسک می‌نشیند. `no-store` یعنی «اصلاً ننویس».
         */
        $response->headers->set('Cache-Control', 'no-store, private');

        /*
         * ⚠️ `Vary: Cookie` صرفاً ادب نیست؛ سیگنالِ دومی است که `sw.js` هم
         * می‌خواندش: «این پاسخ به اینکه چه کسی پرسیده بستگی دارد».
         */
        $response->headers->set('Vary', trim(
            $response->headers->get('Vary', '').', Cookie',
            ', ',
        ));
    }

    /**
     * آیا این مسیر در فهرستِ عمومی است؟
     *
     * هر دو پیشوند سنجیده می‌شوند چون نامِ مستعارِ بدونِ نسخه هنوز زنده است
     * و فراموش‌کردنش یعنی نیمی از مسیرها هدرِ اشتباه بگیرند.
     */
    private static function isPublic(Request $request): bool
    {
        foreach ((array) config('pwa.public_api', []) as $path) {
            if ($request->is('api/'.$path, 'api/v1/'.$path)) {
                return true;
            }
        }

        return false;
    }
}
