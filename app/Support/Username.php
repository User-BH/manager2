<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * نام کاربری — شناسه‌ی ورودِ سوپر ادمین و حساب‌های آزمایشیِ Preview (مرحلهٔ ۱۳ · فاز ۱؛ پیامِ کارفرما ۲۰۲۶-۱۰-۱۹، بخش ۳).
 *
 * با حرفِ لاتین شروع می‌شود، پس هیچ‌گاه شکلِ شماره‌ی موبایل ندارد و هیچ‌کدام جای دیگری تفسیر نمی‌شود. نوعِ شناسه را
 * خودِ درخواست صریح می‌گوید (`identifier_type`)؛ سرور از شکلِ ورودی حدس نمی‌زند.
 */
final class Username
{
    /** ۳ تا ۳۲ نویسه: حرفِ کوچکِ لاتین در آغاز، سپس حرف، رقم، نقطه یا زیرخط */
    public const PATTERN = '/^[a-z][a-z0-9._]{2,31}$/';

    public static function normalize(?string $raw): string
    {
        return Str::lower(trim((string) $raw));
    }

    public static function isValid(?string $raw): bool
    {
        return preg_match(self::PATTERN, self::normalize($raw)) === 1;
    }
}
