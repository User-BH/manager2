<?php

namespace App\Support;

use Carbon\Carbon;
use Morilog\Jalali\CalendarUtils;
use Morilog\Jalali\Jalalian;

/**
 * Central place for Jalali (Persian) calendar conversion and
 * Persian-digit / currency formatting used across the UI.
 */
class Jalali
{
    public const PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    public const MONTHS = [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر',
        5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان',
        9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
    ];

    /**
     * منطقه‌ی زمانیِ **نمایش** (مرحلهٔ ۵ · فاز ۶ · بندِ ۵۱: Timezone).
     *
     * 🔴 نقصی که اندازه‌گیری نشان داد: تصمیمِ مکتوب درست بود — ذخیره UTC،
     * تبدیل فقط هنگامِ نمایش — ولی تبدیلِ منطقه‌ی زمانی هرگز انجام نمی‌شد؛
     * `Jalalian::fromCarbon` همان UTC را شمسی می‌کرد. سنجش:
     *
     *     ذخیره (UTC):   2026-09-22 21:00
     *     نمایش:          ۱۴۰۵/۰۶/۳۱ ۲۱:۰۰     ← سه ساعت و نیم عقب
     *     تهران، واقعی:   1405/07/01 00:30
     *     دوره:           1405-06             ← ماهِ غلط
     *
     * یعنی هر ساعتی که سرور نشان می‌داد عقب بود، و در سه ساعت و نیمِ اولِ هر
     * ماه، گزارش‌ها و نمودارها و حتی «صدورِ شارژِ این ماه» ماهِ قبل را می‌دیدند.
     *
     * ⚠️ ذخیره همچنان UTC است (`config('app.timezone')` دست نخورده)؛ فقط
     * خروجیِ این کلاس به وقتِ محلی است. ستونِ `date` (نیمه‌شبِ UTC) با این
     * تبدیل ساعت ۳:۳۰ همان روز می‌شود — تاریخش جابه‌جا نمی‌شود.
     */
    public static function zone(): string
    {
        return (string) config('app.display_timezone', 'Asia/Tehran');
    }

    /** همان لحظه، به وقتِ محلی — بدونِ دست‌زدن به شیءِ ورودی. */
    private static function local(Carbon $moment): Carbon
    {
        return $moment->copy()->setTimezone(self::zone());
    }

    /** Convert a Gregorian datetime to a formatted Jalali string. */
    public static function date(Carbon|string|null $date, string $format = 'Y/m/d'): string
    {
        if (! $date) {
            return '-';
        }
        $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);

        return self::digits(Jalalian::fromCarbon(self::local($carbon))->format($format));
    }

    public static function dateTime(Carbon|string|null $date): string
    {
        return self::date($date, 'Y/m/d H:i');
    }

    /** Current Jalali period as `YYYY-MM` (e.g. 1404-03). */
    public static function currentPeriod(): string
    {
        // ⚠️ «امروز» به وقتِ تهران، نه UTC: ساعت ۱ بامدادِ اولِ مهر، مهر است
        $now = Jalalian::fromCarbon(Carbon::now(self::zone()));

        return sprintf('%04d-%02d', $now->getYear(), $now->getMonth());
    }

    /**
     * The Jalali period (`YYYY-MM`) a given date falls in.
     *
     * ─── چرا این متد لازم شد (R29) ─────────────────────────────────────────
     * گروه‌بندیِ ماهانه با `GROUP BY MONTH()` در SQL برای تقویمِ شمسی غلط
     * است: مرزِ ماهِ جلالی وسطِ ماهِ میلادی می‌افتد، پس هر نمودارِ رشدی که
     * روی ماهِ میلادی گروه شود دو ماهِ شمسی را با هم قاطی می‌کند.
     */
    public static function period(Carbon|string|null $date): string
    {
        if ($date === null) {
            return self::currentPeriod();
        }

        $jalali = Jalalian::fromCarbon(self::local($date instanceof Carbon ? $date : Carbon::parse($date)));

        return sprintf('%04d-%02d', $jalali->getYear(), $jalali->getMonth());
    }

    /** Human label for a `YYYY-MM` period, e.g. «خرداد ۱۴۰۴». */
    public static function periodLabel(string $period): string
    {
        [$year, $month] = array_map('intval', explode('-', $period) + [1 => 0]);

        return (self::MONTHS[$month] ?? '').' '.self::digits((string) $year);
    }

    /** Resolve the Gregorian due date for a Jalali period and day-of-month. */
    public static function dueDate(string $period, int $day): Carbon
    {
        [$year, $month] = array_map('intval', explode('-', $period));
        [$gy, $gm, $gd] = CalendarUtils::toGregorian($year, $month, min($day, 29));

        return Carbon::create($gy, $gm, $gd)->endOfDay();
    }

    /** Shift a `YYYY-MM` Jalali period by N months (negative = past). */
    public static function shiftPeriod(string $period, int $months): string
    {
        [$year, $month] = array_map('intval', explode('-', $period));
        $index = ($year * 12 + ($month - 1)) + $months;

        return sprintf('%04d-%02d', intdiv($index, 12), ($index % 12) + 1);
    }

    /** Replace ASCII digits with Persian digits. */
    public static function digits(string|int|float|null $value): string
    {
        return str_replace(range('0', '9'), self::PERSIAN_DIGITS, (string) $value);
    }

    /** Format a money amount with thousands separators and Persian digits. */
    public static function money(float|int|string|null $amount): string
    {
        return self::digits(number_format((float) $amount));
    }
}
