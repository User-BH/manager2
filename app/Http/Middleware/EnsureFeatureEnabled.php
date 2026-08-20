<?php

namespace App\Http\Middleware;

use App\Services\Features\FeatureFlags;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * بستنِ مسیرِ یک قابلیتِ خاموش (R44).
 *
 * ─── چرا سمتِ سرور و نه فقط پنهان‌کردن در رابطِ کاربری ───────────────────────
 * ⚠️ پنهان‌کردنِ دکمه هیچ چیزی را نمی‌بندد. کاربری که تبِ باز دارد، اسکریپتی
 * که مستقیم به API می‌زند، یا نسخه‌ی کش‌شده‌ی فرانت در مرورگر — هر سه بدونِ
 * دیدنِ دکمه به همان مسیر می‌رسند.
 *
 * و کاربردِ اصلیِ این پرچم‌ها دقیقاً همان لحظه‌ای است که این فرق می‌کند:
 * وقتی درگاهِ بانکی قطع شده و می‌خواهی **الان** جلوی رفتنِ کاربر به آن را
 * بگیری، نه پس از استقرارِ بعدی.
 *
 * ─── چرا ۴۰۳ و نه ۴۰۴ ──────────────────────────────────────────────────────
 * ۴۰۴ می‌گوید «چنین چیزی نیست» و کاربر فکر می‌کند سامانه خراب است یا
 * اشتباه رفته. ۴۰۳ با پیامِ روشن می‌گوید قابلیت موقتاً خاموش است — که هم
 * درست است و هم کاری می‌کند که پشتیبانی تماسِ کمتری بگیرد.
 */
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $flag): Response
    {
        if (app(FeatureFlags::class)->enabled($flag)) {
            return $next($request);
        }

        $message = 'این قابلیت موقتاً خاموش است. لطفاً بعداً دوباره تلاش کنید.';

        /*
         * برای درخواستِ صفحه (نه API) کاربر باید صفحه ببیند، نه JSON.
         * `expectsJson()` روی درخواست‌های SPA درست جواب می‌دهد چون
         * `Accept: application/json` می‌فرستد.
         */
        if (! $request->expectsJson()) {
            abort(403, $message);
        }

        return response()->json([
            'message' => $message,
            'code' => 'feature_disabled',
            'feature' => $flag,
        ], 403);
    }
}
