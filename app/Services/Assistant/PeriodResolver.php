<?php

namespace App\Services\Assistant;

use App\Support\Jalali;
use Carbon\CarbonImmutable;
use Morilog\Jalali\CalendarUtils;
use Morilog\Jalali\Jalalian;

/**
 * «آبان»، «ماهِ قبل»، «امسال»، «۱۴۰۵/۰۸/۱۰» → بازه‌ی واقعی (بندِ ۴.۱۰).
 *
 * سیاستِ زمانیِ صریح — همان که پاسخ بازگو می‌کند:
 * - همه‌چیز به وقتِ تهران و با تقویمِ شمسیِ همان `morilog/jalali` ِ سرور؛
 * - «ماهِ قبل/گذشته/پیش» = ماهِ شمسیِ پیش از ماهِ جاری (نه سی روزِ اخیر)؛ «این ماه/این دوره» = ماهِ جاری؛
 * - نامِ ماه بی سال = نزدیک‌ترین همان ماه که آغازش گذشته (آبان در مهر یعنی آبانِ پارسال)؛
 * - «این هفته» = شنبه تا جمعه‌ی جاری.
 */
final class PeriodResolver
{
    public function resolve(string $text, ?CarbonImmutable $now = null): ?Period
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone(Jalali::zone());
        $today = Jalalian::fromDateTime($now);

        if (preg_match('/(1[34]\d{2})[\/\-](\d{1,2})[\/\-](\d{1,2})/u', $text, $m) === 1) {
            return $this->day((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        foreach (['امروز' => 0, 'دیروز' => -1, 'فردا' => 1] as $word => $shift) {
            if ($this->has($text, $word)) {
                $day = Jalalian::fromDateTime($now->addDays($shift));

                return $this->day($day->getYear(), $day->getMonth(), $day->getDay());
            }
        }

        if (preg_match('/هفته\s*(?:ی\s*)?(قبل|گذشته|پیش)/u', $text) === 1) {
            return $this->week($now->subWeek(), 'هفته‌ی گذشته');
        }
        if (preg_match('/(این|همین)\s*هفته|هفته\s*(?:ی\s*)?جاری/u', $text) === 1) {
            return $this->week($now, 'هفته‌ی جاری');
        }

        foreach (Jalali::MONTHS as $number => $name) {
            // «آبان»، «آبان‌ماه»، «آبان ماهِ ۱۴۰۴»، «آبانِ سالِ ۱۴۰۴» — نه «دی» درونِ «دیروز» یا «بعدی»
            if (preg_match('/(?<!\pL)'.$name.'(?:\x{200C}?\s*ماه)?(?!\pL)\s*(?:سال\s*)?(1[34]\d{2})?/u', $text, $m) === 1) {
                if (isset($m[1])) {
                    return $this->month((int) $m[1], $number);
                }
                // ماهی که هنوز آغاز نشده مالِ سالِ پیش است
                $year = $number > $today->getMonth() ? $today->getYear() - 1 : $today->getYear();
                $note = $year !== $today->getYear() ? 'سال گفته نشده بود؛ نزدیک‌ترین '.$name.' ِ گذشته در نظر گرفته شد.' : null;

                return $this->month($year, $number, $note);
            }
        }

        if (preg_match('/ماه\s*(?:ی\s*)?(قبل|گذشته|پیش|قبلی)/u', $text) === 1) {
            [$y, $mo] = $this->shift($today->getYear(), $today->getMonth(), -1);

            return $this->month($y, $mo, '«ماهِ قبل» یعنی ماهِ شمسیِ پیش از ماهِ جاری.');
        }
        if (preg_match('/(این|همین)\s*(ماه|دوره)|(ماه|دوره)\s*(?:ی\s*)?جاری/u', $text) === 1) {
            return $this->month($today->getYear(), $today->getMonth());
        }

        if (preg_match('/سال\s*(1[34]\d{2})/u', $text, $m) === 1) {
            return $this->year((int) $m[1]);
        }
        if ($this->has($text, 'پارسال') || preg_match('/سال\s*(?:ی\s*)?(قبل|گذشته|پیش)/u', $text) === 1) {
            return $this->year($today->getYear() - 1);
        }
        if ($this->has($text, 'امسال') || preg_match('/(این|همین)\s*سال|سال\s*(?:ی\s*)?جاری/u', $text) === 1) {
            return $this->year($today->getYear());
        }

        return null;
    }

    /** پیش‌فرضِ پرسشِ مالیِ بی بازه: ماهِ جاری — و پاسخ همین را صریح می‌گوید */
    public function currentMonth(?CarbonImmutable $now = null): Period
    {
        $today = Jalalian::fromDateTime(($now ?? CarbonImmutable::now())->setTimezone(Jalali::zone()));

        return $this->month($today->getYear(), $today->getMonth(), 'بازه گفته نشده بود؛ ماهِ جاری در نظر گرفته شد.');
    }

    public function month(int $year, int $month, ?string $note = null): Period
    {
        [$ny, $nm] = $this->shift($year, $month, 1);

        return new Period(
            Period::MONTH,
            Jalali::MONTHS[$month].' '.Jalali::digits($year),
            $this->startOf($year, $month, 1),
            $this->startOf($ny, $nm, 1),
            [sprintf('%04d-%02d', $year, $month)],
            $note,
        );
    }

    public function year(int $year): Period
    {
        return new Period(
            Period::YEAR,
            'سال '.Jalali::digits($year),
            $this->startOf($year, 1, 1),
            $this->startOf($year + 1, 1, 1),
            array_map(fn (int $m) => sprintf('%04d-%02d', $year, $m), range(1, 12)),
        );
    }

    private function day(int $year, int $month, int $day): Period
    {
        $start = $this->startOf($year, $month, $day);

        return new Period(
            Period::DAY,
            Period::jalali($start, 'l j F Y'),
            $start,
            $start->addDay(),
            [sprintf('%04d-%02d', $year, $month)],
        );
    }

    private function week(CarbonImmutable $moment, string $label): Period
    {
        // شنبه آغازِ هفته‌ی ایرانی است
        $start = $moment->startOfDay()->subDays(($moment->dayOfWeek + 1) % 7);
        $end = $start->addDays(7);
        $periods = array_values(array_unique([Jalali::period($start->toMutable()), Jalali::period($end->subDay()->toMutable())]));

        return new Period(Period::WEEK, $label, $start, $end, $periods);
    }

    private function startOf(int $year, int $month, int $day): CarbonImmutable
    {
        [$gy, $gm, $gd] = CalendarUtils::toGregorian($year, $month, $day);

        return CarbonImmutable::create($gy, $gm, $gd, 0, 0, 0, Jalali::zone());
    }

    /** @return array{0: int, 1: int} */
    private function shift(int $year, int $month, int $by): array
    {
        $index = $year * 12 + ($month - 1) + $by;

        return [intdiv($index, 12), $index % 12 + 1];
    }

    private function has(string $text, string $word): bool
    {
        return preg_match('/(?<!\pL)'.$word.'(?!\pL)/u', $text) === 1;
    }
}
