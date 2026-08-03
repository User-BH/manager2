<?php

namespace App\Enums;

/**
 * دسته‌بندیِ درخواستِ ساکن (R25).
 *
 * فهرست عمداً کوتاه و ثابت است و نه دسته‌ی دلخواهِ هر مجتمع: با دسته‌ی آزاد،
 * سه ساکن سه اسمِ متفاوت برای «آسانسور» می‌نویسند و گزارشِ «بیشترین مشکلِ
 * ساختمان چیست؟» — که تنها دلیلِ وجودِ دسته است — بی‌معنا می‌شود.
 */
enum ServiceRequestCategory: string
{
    case Facilities = 'facilities';   // تاسیسات: آب، برق، گاز، شوفاژ
    case Elevator = 'elevator';
    case Cleaning = 'cleaning';
    case Security = 'security';
    case Parking = 'parking';
    case Financial = 'financial';     // اعتراض به قبض، تسویه
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Facilities => 'تاسیسات',
            self::Elevator => 'آسانسور',
            self::Cleaning => 'نظافت',
            self::Security => 'امنیت',
            self::Parking => 'پارکینگ',
            self::Financial => 'مالی',
            self::Other => 'سایر',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
