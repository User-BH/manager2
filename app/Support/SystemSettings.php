<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * تنظیماتِ سراسریِ سامانه (`complex_id = null`) — مثل پیکربندیِ پیامک،
 * محتوای فوتر و شناسه‌های تحلیلی.
 *
 * ─── چرا همه با هم خوانده می‌شوند و نه کلید به کلید (R13) ───────────────────
 * نسخه‌ی قبلی برای هر کلید یک `Cache::rememberForever` جدا داشت. پروفایل روی
 * صفحه‌ی فرود — که ~۱۰ کلید می‌خواند — نشان داد این یعنی **۱۰ کوئریِ جدا**:
 *
 *     10× select "value" from "settings" where "complex_id" is null and "key" = ?
 *
 * و نکته‌ی مهم اینکه کش جلویش را نمی‌گرفت: درایورِ پیش‌فرضِ کشِ این پروژه
 * `database` است، پس هر «خواندن از کش» خودش یک کوئریِ دیگر بود. کش فقط جدولِ
 * مقصد را عوض می‌کرد، نه تعدادِ رفت‌وبرگشت‌ها را.
 *
 * حالا **یک** کوئری همه‌ی تنظیماتِ سراسری را می‌آورد و بقیه از همان آرایه
 * خوانده می‌شوند. تعدادشان کم و حجمشان ناچیز است، پس یک‌جا آوردنشان ارزان‌تر
 * از ده بار پرسیدن است.
 */
class SystemSettings
{
    private const CACHE_KEY = 'settings:global';

    /**
     * حافظه‌ی درون‌درخواستی.
     *
     * حتی با کشِ سریع، هر `Cache::get` یک بار سریال‌زدایی دارد؛ نگه‌داشتنِ
     * نتیجه در همین کلاس آن را هم حذف می‌کند.
     *
     * @var array<string, string|null>|null
     */
    private static ?array $memo = null;

    /**
     * همه‌ی تنظیماتِ سراسری، با یک کوئری.
     *
     * @return array<string, string|null>
     */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        /** @var array<string, string|null> $settings */
        $settings = Cache::rememberForever(
            self::CACHE_KEY,
            fn () => Setting::whereNull('complex_id')->pluck('value', 'key')->all(),
        );

        return self::$memo = $settings;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    /**
     * @param  array<mixed>  $default
     * @return array<mixed>
     */
    public static function getJson(string $key, array $default = []): array
    {
        $raw = self::get($key);

        return $raw ? (json_decode($raw, true) ?: $default) : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        Setting::updateOrCreate(['complex_id' => null, 'key' => $key], ['value' => $value]);

        self::forget();
    }

    /**
     * باطل‌کردنِ کش و حافظه.
     *
     * عمومی است چون مهاجرت‌ها و دستورهای artisan هم می‌توانند مستقیم جدول را
     * عوض کنند و بعد باید بتوانند کش را بی‌اعتبار کنند.
     */
    public static function forget(): void
    {
        self::$memo = null;
        Cache::forget(self::CACHE_KEY);
    }
}
