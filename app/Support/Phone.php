<?php

namespace App\Support;

class Phone
{
    /**
     * Normalise an Iranian mobile number to the canonical 09xxxxxxxxx form.
     * Accepts Persian/Arabic digits, spaces, +98 / 0098 / 98 prefixes.
     * Returns the cleaned string (best effort) so validation can judge it.
     */
    public static function normalize(?string $raw): string
    {
        if ($raw === null) {
            return '';
        }

        // Convert Persian (۰-۹) and Arabic (٠-٩) digits to ASCII.
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $raw = str_replace($persian, range(0, 9), $raw);
        $raw = str_replace($arabic, range(0, 9), $raw);

        // Keep digits only.
        $digits = preg_replace('/\D+/', '', $raw);

        // Strip country code variants to a leading 0.
        if (str_starts_with($digits, '0098')) {
            $digits = '0'.substr($digits, 4);
        } elseif (str_starts_with($digits, '98') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 2);
        } elseif (str_starts_with($digits, '9') && strlen($digits) === 10) {
            $digits = '0'.$digits;
        }

        return $digits;
    }

    /** A valid Iranian mobile looks like 09 followed by 9 digits. */
    public static function isValidMobile(?string $raw): bool
    {
        return (bool) preg_match('/^09\d{9}$/', self::normalize($raw));
    }
}
