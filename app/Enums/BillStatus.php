<?php

namespace App\Enums;

enum BillStatus: string
{
    case Unpaid = 'unpaid';   // پرداخت‌نشده
    case Partial = 'partial'; // پرداخت جزئی
    case Pending = 'pending'; // رسید در انتظار تایید
    case Paid = 'paid';       // تسویه‌شده

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'پرداخت‌نشده',
            self::Partial => 'پرداخت جزئی',
            self::Pending => 'در انتظار تایید',
            self::Paid => 'تسویه‌شده',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unpaid => 'rose',
            self::Partial => 'amber',
            self::Pending => 'sky',
            self::Paid => 'emerald',
        };
    }
}
