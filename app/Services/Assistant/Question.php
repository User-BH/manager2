<?php

namespace App\Services\Assistant;

/**
 * پرسشِ خوانده‌شده — لایه‌ی دریافت (بندِ ۴.۶، لایه‌ی ۱). فقط آنچه در متن هست؛ هیچ مجوزی از این‌جا نمی‌آید.
 */
final class Question
{
    public function __construct(
        /** متنِ اصلیِ کاربر، برای تاریخچه */
        public readonly string $original,
        /** یکدست: «ی/ک» ِ فارسی، ارقامِ لاتین، بی اعراب و نشانه */
        public readonly string $text,
        /** همان بی فاصله و نیم‌فاصله — برای تطبیقِ کلیدواژه («پرداخت‌های» = «پرداختهای») */
        public readonly string $compact,
        public readonly ?string $unit,
        public readonly ?Period $period,
        /** نامِ شخص پس از «آقای/خانم» یا پس از «شماره‌ی …» */
        public readonly ?string $person,
        public readonly bool $wantsContact,
    ) {}

    /** یکی از این کلیدواژه‌ها (به شکلِ فشرده) در پرسش هست؟ */
    public function mentions(string ...$keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($this->compact, QuestionParser::compact($keyword))) {
                return true;
            }
        }

        return false;
    }
}
