<?php

namespace App\Casts;

use App\Support\SecretValue;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * ستونی که آرایه نگه می‌دارد و **رمزشده** روی دیسک می‌نشیند (R55).
 *
 * ─── چرا کستِ خودی و نه `encrypted:array`ِ لاراول ──────────────────────────
 * ⚠️ کستِ آماده‌ی لاراول تحمل ندارد: اولین ردیفِ متنِ سادهٔ قدیمی که بخواند،
 * استثنا می‌دهد. این پروژه ردیف‌های موجود دارد و مهاجرتشان نمی‌تواند
 * لحظه‌ای باشد.
 *
 * ─── 🔴 چرا متنِ رمزشده داخلِ یک «پاکتِ JSON» می‌نشیند ──────────────────────
 * ⚠️ این را **اجرای واقعی روی MySQL** نشان داد، نه حدس. نسخه‌ی اول
 * رمزنگاشت را خام در ستون می‌نوشت و روی سرور شکست:
 *
 *     SQLSTATE[22032]: 3140 Invalid JSON text: "Invalid value." at position 0
 *     in value for column 'complexes.gateway_config'
 *
 * ستون با `$table->json(...)` ساخته شده. در MySQL این یک **نوعِ واقعی**
 * است و موتور محتوایش را اعتبارسنجی می‌کند؛ رشته‌ی base64ِ رمزنگاشت JSON
 * نیست، پس رد می‌شود. روی SQLite همان ستون فقط TEXT است و هر چیزی را
 * می‌پذیرد — برای همین همه‌ی تست‌های محلی سبز بودند.
 *
 * راه‌حل **بدونِ تغییرِ schema**: رمزنگاشت داخلِ یک شیءِ JSON قرار می‌گیرد.
 *
 *     {"__enc":"eyJpdiI6..."}
 *
 * ستون JSONِ معتبر می‌ماند، MySQL راضی است، و هیچ مهاجرتِ ساختاری —
 * که روی جدولِ زنده گران و برگشت‌ناپذیر است — لازم نمی‌شود.
 *
 * ─── سه شکلی که خوانده می‌شوند ─────────────────────────────────────────────
 * ① پاکتِ `{"__enc":"..."}` — شکلِ امروز
 * ② رمزنگاشتِ خام — اگر ردیفی پیش از این رفع نوشته شده باشد
 * ③ JSONِ سادهٔ قدیمی — ردیف‌هایی که هنوز مهاجرت نکرده‌اند
 *
 * نوشتن **همیشه** شکلِ ① است.
 *
 * @implements CastsAttributes<array<mixed>, array<mixed>|string>
 */
class EncryptedJson implements CastsAttributes
{
    /** کلیدِ پاکت. با دو زیرخط شروع می‌شود تا با هیچ کلیدِ واقعی اشتباه نشود. */
    public const ENVELOPE_KEY = '__enc';

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<mixed>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = self::decode((string) $value, $model::class.'::'.$key);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        $json = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);

        return [$key => self::wrap((string) $json)];
    }

    /**
     * متنِ ساده را در پاکتِ JSON می‌گذارد.
     *
     * ⚠️ مهاجرتِ داده هم همین را صدا می‌زند، تا شکلِ نوشتن **یک جا**
     * تعریف شده باشد و کست و مهاجرت نتوانند از هم واگرا شوند.
     */
    public static function wrap(string $plainJson): string
    {
        return (string) json_encode(
            [self::ENVELOPE_KEY => SecretValue::protect($plainJson)],
            JSON_UNESCAPED_UNICODE,
        );
    }

    /** آیا این مقدار از قبل در پاکت است؟ (مهاجرت برای تکرارپذیری لازمش دارد) */
    public static function isWrapped(string $stored): bool
    {
        $decoded = json_decode($stored, true);

        return is_array($decoded)
            && array_key_exists(self::ENVELOPE_KEY, $decoded)
            && is_string($decoded[self::ENVELOPE_KEY]);
    }

    /**
     * هر سه شکل را به آرایه‌ی متنِ ساده برمی‌گرداند.
     *
     * @return array<mixed>|null
     */
    public static function decode(string $stored, string $context): ?array
    {
        $decoded = json_decode($stored, true);

        // ① پاکت
        if (is_array($decoded) && is_string($decoded[self::ENVELOPE_KEY] ?? null)) {
            $plain = SecretValue::reveal($decoded[self::ENVELOPE_KEY], $context);

            $inner = json_decode($plain, true);

            return is_array($inner) ? $inner : null;
        }

        /*
         * 🔴 شکلِ ③ (JSONِ سادهٔ قدیمی) دیگر بی‌صدا پذیرفته نمی‌شود — فاز ۴.
         *
         * ⚠️ تا پیش از این، ردیفِ رمزنشده مثلِ ردیفِ سالم خوانده می‌شد و
         * هیچ نشانه‌ای نمی‌داد. یعنی نصبی که مهاجرتش را اجرا نکرده بود
         * می‌توانست ماه‌ها با اعتبارنامه‌ی خام کار کند و کسی نفهمد.
         *
         * حالا به `reveal` سپرده می‌شود، که پیامِ دقیق و راهِ اصلاح را
         * می‌دهد. تشخیصِ «رمزنشده» از «کلید عوض شده» هم آنجاست.
         */
        if (is_array($decoded)) {
            SecretValue::reveal((string) json_encode($decoded, JSON_UNESCAPED_UNICODE), $context);
        }

        // ② رمزنگاشتِ خام — یا چیزی که هیچ‌کدام نیست، که `reveal` صدایش را درمی‌آورد
        $inner = json_decode(SecretValue::reveal($stored, $context), true);

        return is_array($inner) ? $inner : null;
    }
}
