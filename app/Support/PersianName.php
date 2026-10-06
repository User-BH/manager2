<?php

namespace App\Support;

/**
 * نامِ فارسیِ یک شخص — یک قاعده برای ثبت‌نام، پروفایل و فرمِ بازخورد.
 *
 * تا مرحلهٔ ۹ این قاعده فقط در `StoreFeedbackRequest` بود؛ ثبت‌نام هر رشته‌ای تا
 * ۲۵۵ نویسه را می‌پذیرفت و پروفایل هر حرفی از هر خطی را. حالا هر سه از همین‌جا
 * می‌خوانند تا «نامِ معتبر» یک تعریف داشته باشد (همتای `shared/lib/personName.ts`).
 */
final class PersianName
{
    /** فقط حرفِ فارسی، فاصله و نیم‌فاصله؛ نه رقم، نه نشانه، نه حرفِ لاتین. */
    public const PATTERN = '/^[\x{0621}-\x{063A}\x{0641}-\x{064A}\x{067E}\x{0686}\x{0698}\x{06A9}\x{06AF}\x{06CC}\x{200C} ]+$/u';

    /** سقفِ نام یا نام خانوادگی. */
    public const PART_MAX = 60;

    /**
     * پاک‌سازی پیش از سنجش: نویسه‌ی کنترلی و بازنویسیِ جهت حذف، «ي/ى/ك» ِ عربی
     * به «ی/ک» ِ فارسی، و فاصله‌ی پشتِ‌هم یکی. رشته‌ی خالی ← `null`.
     *
     * ⚠️ نیم‌فاصله (U+200C) دست نمی‌خورد؛ بی آن نامِ فارسی می‌شکند.
     */
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $clean = (string) preg_replace('/\p{Cc}|[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $value);
        $clean = strtr($clean, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک']);
        $clean = trim((string) preg_replace('/ {2,}/', ' ', $clean));

        return $clean === '' ? null : $clean;
    }

    /** نامِ نمایشی از نام و نام خانوادگی — تنها جایی که `users.name` ساخته می‌شود. */
    public static function full(string $first, string $last): string
    {
        return trim($first.' '.$last);
    }

    /**
     * قاعده‌ی اعتبارسنجیِ یک بخشِ نام.
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        return ['required', 'string', 'min:2', 'max:'.self::PART_MAX, 'regex:'.self::PATTERN];
    }
}
