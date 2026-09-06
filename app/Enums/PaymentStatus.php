<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';   // در انتظار تایید / در انتظار درگاه
    case Success = 'success';   // موفق
    case Failed = 'failed';     // ناموفق
    case Rejected = 'rejected'; // رسید رد شده

    /**
     * گذارهای مجاز — صریح، چون سکوت اینجا یعنی «هر چیزی مجاز است».
     *
     * ─── 🔴 چرا سه حالت پایانی‌اند ─────────────────────────────────────────
     * `Success` یعنی پول جابه‌جا شده و روی بدهی نشسته. برگرداندنِ وضعیت،
     * پول را برنمی‌گرداند — فقط دفتر را با واقعیت ناسازگار می‌کند.
     *
     * `Failed` و `Rejected` یعنی قطعاً جابه‌جا **نشده**. زنده‌کردنشان یعنی
     * پولی که هرگز نیامده روی بدهی بنشیند؛ همان حمله‌ی `failed → paid`.
     *
     * ⚠️ تلاشِ دوباره با **پرداختِ تازه** انجام می‌شود، نه با زنده‌کردنِ
     * پرداختِ مرده. تاریخچه باید بتواند بگوید چند بار تلاش شد.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Success, self::Failed, self::Rejected],
            self::Success, self::Failed, self::Rejected => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** حالتی که راهِ خروج ندارد. */
    public function isFinal(): bool
    {
        return $this->allowedNext() === [];
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'در انتظار تایید',
            self::Success => 'موفق',
            self::Failed => 'ناموفق',
            self::Rejected => 'رد شده',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Success => 'emerald',
            self::Failed => 'rose',
            self::Rejected => 'rose',
        };
    }
}
