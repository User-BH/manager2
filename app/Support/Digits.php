<?php

namespace App\Support;

/**
 * ارقامِ فارسی و عربی ← لاتین، پیش از اعتبارسنجی.
 *
 * از `UpdateProfileRequest` بیرون آمد (مرحلهٔ ۱۰ · فاز ۷) تا کدِ پستیِ مجتمع و شماره‌های واحد هم همان قاعده را
 * داشته باشند، نه نسخه‌ی دوم. درخواستِ مستقیمِ API هم باید مثلِ کلاینت پاک شود.
 */
final class Digits
{
    private const FROM = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    private const TO = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    public static function toLatin(mixed $value): mixed
    {
        return is_string($value) && $value !== '' ? str_replace(self::FROM, self::TO, $value) : $value;
    }
}
