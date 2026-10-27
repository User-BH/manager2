<?php

namespace App\Services\Assistant;

use App\Support\Jalali;
use Carbon\CarbonImmutable;
use Morilog\Jalali\Jalalian;

/**
 * بازه‌ی زمانیِ پرسش، به وقتِ تهران (بندِ ۴.۱۰).
 *
 * `[start, end)` — آغاز شامل، پایان نه؛ پس رکوردِ لحظه‌ی آخرِ روزِ آخرِ ماه بیرون نمی‌ماند و نخستین لحظه‌ی ماهِ بعد
 * درون نمی‌آید. `periods` دوره‌های ماهانه‌ی «YYYY-MM» ِ همان بازه است — مبنای دفترِ مالی (هزینه، درآمد، قبض)؛ بازه‌ای
 * که با مرزِ ماه جور نیست (روز و هفته) با تاریخِ خودِ رکورد سنجیده می‌شود.
 */
final class Period
{
    public const DAY = 'day';

    public const WEEK = 'week';

    public const MONTH = 'month';

    public const YEAR = 'year';

    /**
     * @param  list<string>  $periods
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $label,
        /** آغازِ بازه به وقتِ تهران */
        public readonly CarbonImmutable $start,
        /** پایانِ بازه (بیرون از بازه) به وقتِ تهران */
        public readonly CarbonImmutable $end,
        public readonly array $periods,
        /** وقتی سیاستِ زمانی چیزی را تعیین کرد که پرسش نگفته بود (مثلاً «ماهِ قبل» یا سالِ ناگفته) */
        public readonly ?string $note = null,
    ) {}

    public function monthAligned(): bool
    {
        return $this->kind === self::MONTH || $this->kind === self::YEAR;
    }

    public function startUtc(): CarbonImmutable
    {
        return $this->start->utc();
    }

    public function endUtc(): CarbonImmutable
    {
        return $this->end->utc();
    }

    /** نخستین و آخرین روزِ تقویمیِ بازه («Y-m-d» ِ میلادی) — برای ستون‌های `date` */
    public function firstDay(): string
    {
        return $this->start->toDateString();
    }

    public function lastDay(): string
    {
        return $this->end->subDay()->toDateString();
    }

    /** «آبان ۱۴۰۵ (۱ تا ۳۰ آبان)» — بازه‌ی دقیق تا پاسخ قابلِ بازبینی باشد */
    public function describe(): string
    {
        if ($this->kind === self::DAY) {
            return $this->label;
        }

        return $this->label.' ('.self::jalali($this->start).' تا '.self::jalali($this->end->subDay()).')';
    }

    /** تاریخِ تهرانیِ داده‌شده، شمسی — بی جابه‌جاییِ منطقه (ورودی خودش به وقتِ تهران است) */
    public static function jalali(CarbonImmutable $moment, string $format = 'j F Y'): string
    {
        return Jalali::digits(Jalalian::fromDateTime($moment)->format($format));
    }
}
