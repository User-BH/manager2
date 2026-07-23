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

    /**
     * سقف تعداد واحد. null یعنی نامحدود.
     *
     * این عدد واقعاً هنگام ساخت واحد بررسی می‌شود (UnitController)، نه اینکه
     * فقط روی صفحه‌ی قیمت‌ها نوشته شود.
     */
    public function unitLimit(): ?int
    {
        return match ($this) {
            self::Free => 20,
            self::Pro, self::ProYearly => null,
        };
    }

    /** اجازه‌ی اتصال درگاه بانکی واقعی (نه سندباکس). */
    public function allowsRealGateway(): bool
    {
        return $this !== self::Free;
    }

    /** اجازه‌ی گرفتن خروجی Excel از قبوض. */
    public function allowsExcelExport(): bool
    {
        return $this !== self::Free;
    }

    /**
     * فهرست امکانات.
     *
     * این متن‌ها عمداً فقط چیزهایی را می‌گویند که واقعاً در کد اعمال می‌شوند؛
     * وعده‌های اعمال‌نشده (بکاپ خودکار روزانه، پشتیبانی اولویت‌دار) حذف
     * شده‌اند تا صفحه‌ی خرید چیزی را تبلیغ نکند که تحویل نمی‌دهد.
     *
     * @return string[]
     */
    public function features(): array
    {
        return match ($this) {
            self::Free => [
                'تا ۲۰ واحد',
                'صدور قبض و شارژ ماهانه',
                'پرداخت با آپلود رسید',
                'اطلاعیه و پیام‌رسان',
                'خروجی PDF فاکتور و تسویه‌حساب',
            ],
            self::Pro, self::ProYearly => [
                'واحد نامحدود',
                'اتصال درگاه پرداخت بانکی (ملت / سامان)',
                'خروجی Excel از قبوض هر دوره',
                'همه‌ی امکانات پلن رایگان',
            ],
        };
    }

    /** پلن‌هایی که در صفحه‌ی تنظیمات حساب قابل خریدند. */
    public static function purchasable(): array
    {
        return [self::Pro, self::ProYearly];
    }
}
