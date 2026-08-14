<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * آدرسِ متعارفِ صفحه — همیشه از `APP_URL`، هرگز از هدرِ درخواست (R38).
 *
 * ─── آسیب‌پذیری‌ای که این کلاس می‌بندد ──────────────────────────────────────
 * ⚠️ پوسته‌ی عمومی `url()->current()` می‌نوشت که میزبان را از هدرِ `Host`ِ
 * خودِ درخواست می‌گیرد. یعنی:
 *
 *     curl -H "Host: evil.example.com" https://sakena.ir/
 *     → <link rel="canonical" href="http://evil.example.com">
 *
 * این در مرورگر آزموده شد و دقیقاً همین خروجی را داد. پیامدش دو چیز است:
 * موتورِ جستجو می‌تواند محتوای ما را به دامنه‌ی مهاجم نسبت بدهد، و
 * `og:url`ِ دستکاری‌شده در پیام‌رسان‌ها لینکِ دیگری نشان می‌دهد.
 *
 * ─── چرا `config('app.url')` ───────────────────────────────────────────────
 * چون تنها چیزی است که **ما** تعیینش می‌کنیم، نه فرستنده‌ی درخواست. مسیر و
 * کوئری از درخواست می‌آیند (آن‌ها بی‌خطرند)، ولی میزبان و طرح هرگز.
 */
class CanonicalUrl
{
    /**
     * ریشه‌ی سایت، بدونِ اسلشِ پایانی.
     */
    public static function base(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * آدرسِ متعارفِ همین درخواست.
     *
     * ⚠️ کوئری‌استرینگ عمداً حذف می‌شود: `?source=pwa` و `?utm_*` همان صفحه‌اند
     * و اگر در canonical بیایند، موتورِ جستجو هر کدام را صفحه‌ی جداگانه
     * می‌بیند و اعتبارِ صفحه بین نسخه‌های تکراری خرد می‌شود.
     */
    public static function forRequest(Request $request): string
    {
        $path = trim($request->getPathInfo(), '/');

        return $path === '' ? self::base().'/' : self::base().'/'.$path;
    }

    /**
     * آدرسِ مطلقِ یک فایلِ عمومی، برای OG و JSON-LD.
     *
     * پیام‌رسان‌ها آدرسِ نسبی را نمی‌فهمند؛ باید مطلق و مستقیم باشد.
     */
    public static function asset(string $path): string
    {
        return self::base().'/'.ltrim($path, '/');
    }
}
