<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Online = 'online';   // درگاه پرداخت
    case Receipt = 'receipt'; // آپلود رسید
    case Cash = 'cash';       // ثبت دستی توسط مدیر

    public function label(): string
    {
        return match ($this) {
            self::Online => 'پرداخت آنلاین',
            self::Receipt => 'آپلود رسید',
            self::Cash => 'نقدی / ثبت دستی',
        };
    }
}
