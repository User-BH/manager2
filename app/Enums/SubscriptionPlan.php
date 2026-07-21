<?php

namespace App\Enums;

/**
 * پلن‌های اشتراک سرویس.
 *
 * قیمت‌ها اینجا و نه در دیتابیس نگه داشته می‌شوند تا کلاینت نتواند مبلغ را
 * تعیین کند؛ کلاینت فقط نام پلن را می‌فرستد و مبلغ سمت سرور خوانده می‌شود.
 */
enum SubscriptionPlan: string
{
    case Free = 'free';
    case Pro = 'pro';
    case ProYearly = 'pro_yearly';

    public function label(): string
    {
        return match ($this) {
            self::Free => 'رایگان',
            self::Pro => 'پرو ماهانه',
            self::ProYearly => 'پرو سالانه',
        };
    }

    /** مبلغ به تومان. */
    public function price(): int
    {
        return match ($this) {
            self::Free => 0,
            self::Pro => 490_000,
            self::ProYearly => 4_900_000,
        };
    }

    public function months(): int
    {
        return match ($this) {
            self::Free => 0,
            self::Pro => 1,
            self::ProYearly => 12,
        };
    }

    public function isPaid(): bool
    {
        return $this->price() > 0;
    }

    /** @return string[] */
    public function features(): array
    {
        return match ($this) {
            self::Free => [
                'تا ۲۰ واحد',
                'صدور قبض و شارژ ماهانه',
                'اطلاعیه و پیام‌رسان',
                'گزارش‌های پایه',
            ],
            self::Pro, self::ProYearly => [
                'واحد نامحدود',
                'درگاه پرداخت آنلاین اختصاصی',
                'پنل پیامک و یادآور خودکار سررسید',
                'خروجی Excel و PDF از همه‌ی گزارش‌ها',
                'بکاپ خودکار روزانه',
                'پشتیبانی اولویت‌دار',
            ],
        };
    }

    /** پلن‌هایی که در صفحه‌ی تنظیمات حساب قابل خریدند. */
    public static function purchasable(): array
    {
        return [self::Pro, self::ProYearly];
    }
}
