<?php

namespace App\Enums;

/**
 * رأی چطور شمرده می‌شود (R24).
 *
 * ─── چرا سه حالت و نه یکی ───────────────────────────────────────────────────
 * در یک ساختمان هر سه حالت واقعاً استفاده می‌شوند و هیچ‌کدام جایگزینِ دیگری
 * نیست:
 *
 *   • `PerPerson` — نظرسنجیِ سلیقه‌ای («رنگِ گلدان‌های لابی؟»). هرکس یک نظر.
 *   • `PerUnit` — تصمیمِ مشترک. واحدی که چهار ساکن دارد نباید چهار برابرِ
 *     واحدِ تک‌نفره وزن داشته باشد.
 *   • `ByArea` — تصمیمِ هزینه‌بر. وقتی سهمِ هزینه بر اساس متراژ است، وزنِ
 *     رأی هم منطقاً باید همان باشد؛ وگرنه واحدِ ۶۰ متری می‌تواند برای واحدِ
 *     ۲۰۰ متری خرج بتراشد.
 */
enum PollWeightMode: string
{
    case PerPerson = 'per_person';
    case PerUnit = 'per_unit';
    case ByArea = 'by_area';

    public function label(): string
    {
        return match ($this) {
            self::PerPerson => 'هر نفر یک رأی',
            self::PerUnit => 'هر واحد یک رأی',
            self::ByArea => 'وزنی بر اساس متراژ',
        };
    }

    /** آیا رأی به واحد بسته است؟ در این حالت، رأیِ دومِ همان واحد تکراری است. */
    public function isUnitBound(): bool
    {
        return $this !== self::PerPerson;
    }

    /** واحدِ نمایشیِ نتیجه — «۳ رأی» در برابرِ «۱۸۰ متر مربع». */
    public function unitLabel(): string
    {
        return $this === self::ByArea ? 'متر مربع' : 'رأی';
    }
}
