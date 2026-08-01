<?php

namespace App\Support;

class Json
{
    /**
     * JSON‌ای که امن است داخلِ یک تگِ `<script>` چاپ شود.
     *
     * ─── مشکلی که حل می‌کند ────────────────────────────────────────────────
     * تجزیه‌گرِ HTML محتوای `<script>` را نمی‌فهمد؛ فقط دنبالِ رشته‌ی
     * `</script>` می‌گردد. پس اگر **هر مقداری** درون JSON آن رشته را داشته
     * باشد، تگ همان‌جا بسته می‌شود و هر چه بعدش بیاید HTMLِ اجراشدنی است:
     *
     *     {"id":"</script><script>alert(1)</script>"}
     *
     * `json_encode` به‌طور پیش‌فرض `/` را `\/` می‌کند و همین تصادفاً جلویش را
     * می‌گرفت — ولی فلگِ `JSON_UNESCAPED_SLASHES` (که برای خوانایی گذاشته شده
     * بود) دقیقاً همان محافظتِ تصادفی را برمی‌داشت.
     *
     * `JSON_HEX_TAG` به‌جای تکیه بر تصادف، `<` و `>` را به `<` و `>`
     * تبدیل می‌کند. نتیجه هنوز JSONِ معتبر است و `JSON.parse` دقیقاً همان
     * رشته‌ی اصلی را برمی‌گرداند — پس هیچ رفتاری عوض نمی‌شود.
     *
     * `JSON_HEX_AMP|APOS|QUOT` هم اضافه شده‌اند تا اگر روزی همین خروجی داخلِ
     * یک ویژگیِ HTML یا `ld+json` استفاده شد، باز هم امن بماند.
     *
     * ⚠️ **هر `json_encode`ای که خروجی‌اش داخلِ `<script>` می‌رود باید از این
     * متد رد شود.** تستِ `InputOutputSecurityTest` نگهبانِ این قاعده است.
     */
    public static function forScript(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT,
        );
    }
}
