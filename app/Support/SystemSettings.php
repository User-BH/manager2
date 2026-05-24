<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Key/value store for global (complex_id = null) system settings,
 * e.g. the SMS provider configuration used for login OTP.
 */
class SystemSettings
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = Cache::rememberForever('setting:'.$key, fn () => Setting::whereNull('complex_id')->where('key', $key)->value('value'));

        return $value ?? $default;
    }

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
        Cache::forget('setting:'.$key);
    }
}
