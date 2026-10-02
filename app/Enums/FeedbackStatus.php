<?php

namespace App\Enums;

/** وضعیتِ بازخورد در صفِ ادمینِ کل (مرحلهٔ ۷ · فاز ۴). */
enum FeedbackStatus: string
{
    case New = 'new';

    case Read = 'read';

    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::New => 'تازه',
            self::Read => 'خوانده‌شده',
            self::Archived => 'بایگانی',
        };
    }
}
