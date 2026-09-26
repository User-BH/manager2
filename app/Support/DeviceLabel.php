<?php

namespace App\Support;

/**
 * نامِ خوانای دستگاه از رشته‌ی مرورگر، مثلِ «Chrome · Android».
 *
 * فقط برای نمایش به صاحبِ حساب در فهرستِ نشست‌ها و تاریخچه‌ی ورود است، نه
 * برای تصمیمِ امنیتی: رشته‌ی مرورگر را هر کسی می‌تواند جعل کند.
 *
 * ⚠️ ترتیب مهم است: رشته‌ی Edge «Chrome» را هم دارد و Chrome «Safari» را.
 */
final class DeviceLabel
{
    private const BROWSERS = [
        'Edge' => '/Edg(e|A|iOS)?\//',
        'Samsung Internet' => '/SamsungBrowser\//',
        'Opera' => '/OPR\/|Opera/',
        'Firefox' => '/Firefox\/|FxiOS\//',
        'Chrome' => '/Chrome\/|CriOS\//',
        'Safari' => '/Safari\//',
    ];

    private const SYSTEMS = [
        'iOS' => '/iPhone|iPad|iPod/',
        'Android' => '/Android/',
        'Windows' => '/Windows/',
        'macOS' => '/Mac OS X|Macintosh/',
        'Linux' => '/Linux/',
    ];

    public static function from(?string $userAgent): string
    {
        if (! $userAgent) {
            return 'دستگاهِ ناشناس';
        }

        $browser = self::first(self::BROWSERS, $userAgent);
        $system = self::first(self::SYSTEMS, $userAgent);

        $parts = array_filter([$browser, $system]);

        return $parts ? implode(' · ', $parts) : 'دستگاهِ ناشناس';
    }

    /** @param  array<string, string>  $patterns */
    private static function first(array $patterns, string $userAgent): ?string
    {
        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return $name;
            }
        }

        return null;
    }
}
