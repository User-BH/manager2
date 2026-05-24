<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';   // در انتظار تایید / در انتظار درگاه
    case Success = 'success';   // موفق
    case Failed = 'failed';     // ناموفق
    case Rejected = 'rejected'; // رسید رد شده

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
