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
 * `SecretValue` هر دو شکل را می‌خواند و **همیشه** رمزشده می‌نویسد، پس
 * مهاجرت می‌تواند تدریجی و حتی نیمه‌کاره باشد بی‌آنکه چیزی بشکند.
 *
 * ⚠️ نوعِ ورودیِ `set` عمداً `array<mixed>|string` است.
 *
 * Eloquent اجازه می‌دهد همان صفت با رشته‌ی JSON هم مقداردهی شود، و
 * مهاجرت‌ها گاهی همین کار را می‌کنند. باریک‌کردنِ نوع به آرایه، تحلیلِ
 * ایستا را به این نتیجه می‌رساند که شاخه‌ی رشته‌ای مرده است — در حالی که
 * نیست.
 *
 * @implements CastsAttributes<array<mixed>, array<mixed>|string>
 */
class EncryptedJson implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<mixed>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $plain = SecretValue::reveal((string) $value, $model::class.'::'.$key);

        $decoded = json_decode($plain, true);

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

        return [$key => SecretValue::protect((string) $json)];
    }
}
