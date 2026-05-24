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

    /** Convert a Gregorian datetime to a formatted Jalali string. */
    public static function date(Carbon|string|null $date, string $format = 'Y/m/d'): string
    {
        if (! $date) {
            return '-';
        }
        $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);

        return self::digits(Jalalian::fromCarbon($carbon)->format($format));
    }

    public static function dateTime(Carbon|string|null $date): string
    {
        return self::date($date, 'Y/m/d H:i');
    }

    /** Current Jalali period as `YYYY-MM` (e.g. 1404-03). */
    public static function currentPeriod(): string
    {
        $now = Jalalian::now();

        return sprintf('%04d-%02d', $now->getYear(), $now->getMonth());
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
