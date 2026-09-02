<?php

namespace App\Http\Middleware;

use App\Support\AppBuild;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * اعلامِ نسخه‌ی فرانت روی هر پاسخِ API (R61).
 *
 * ─── چرا هدر و نه فیلدی در بدنه ────────────────────────────────────────────
 * ⚠️ بدنه‌ی هر endpoint شکلِ خودش را دارد و افزودنِ فیلد به همه‌شان یعنی
 * دست‌زدن به ده‌ها کنترلر — و شکستنِ همان قراردادی که این مرحله برای
 * حفظش هست.
 *
 * هدر بیرونِ قرارداد است: کلاینتِ قدیمی نادیده‌اش می‌گیرد و کلاینتِ تازه
 * می‌خواندش. یعنی خودِ این سازوکار **سازگارِ رو به عقب** است.
 *
 * ─── قاعده‌ی رفتاری ────────────────────────────────────────────────────────
 * صفحه با HTML خودش یک `<meta name="app-build">` گرفته است. اگر این هدر
 * با آن فرق کند، یعنی از وقتی این تب باز شده استقرار انجام شده.
 *
 * ⚠️ کلاینت **بارگذاری دوباره را به کاربر تحمیل نمی‌کند** — نواری نشان
 * می‌دهد. کاربری که وسطِ پرکردنِ فرمِ هزینه یا پرداخت است، نباید کارش را
 * از دست بدهد؛ همان استدلالِ `skipWaiting` که در R35 گرفته شد.
 */
class AppBuildHeader
{
    public const HEADER = 'X-App-Build';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        self::apply($request, $response);

        return $response;
    }

    /**
     * 🔴 جدا از `handle`، چون پاسخِ **خطا** مهم‌ترین حالت است.
     *
     * ⚠️ وقتی نسخه‌ی فرانت و API از هم جدا می‌افتند، اولین نشانه معمولاً
     * خطاست — ۴۲۲ روی فیلدی که دیگر نیست، ۴۰۴ روی مسیری که عوض شده. و
     * دقیقاً همان پاسخ‌ها از «پس‌ازِ» میدل‌ورها رد نمی‌شوند.
     *
     * یعنی بدونِ قلابِ `respond`، این سازوکار در بدترین لحظه ساکت بود.
     */
    public static function apply(Request $request, Response $response): void
    {
        if ($request->is('api/*')) {
            $response->headers->set(self::HEADER, AppBuild::current());
        }
    }
}
