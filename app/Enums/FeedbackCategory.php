<?php

namespace App\Enums;

/**
 * نوعِ بازخورد (مرحلهٔ ۷ · فاز ۴).
 *
 * ⚠️ «پیشنهادِ قابلیتِ جدید» و «درخواستِ ویژگیِ جدید» در فایلِ ایرادات دو مورد
 * بودند ولی یک چیزند؛ دو گزینه‌ی هم‌معنا فقط کاربر را سرِ انتخاب معطل می‌کرد.
 * یک گزینه شدند.
 */
enum FeedbackCategory: string
{
    case Feedback = 'feedback';

    case Feature = 'feature';

    case Improvement = 'improvement';

    case Criticism = 'criticism';

    case Bug = 'bug';

    public function label(): string
    {
        return match ($this) {
            self::Feedback => 'بازخورد',
            self::Feature => 'پیشنهاد یا درخواستِ قابلیتِ تازه',
            self::Improvement => 'پیشنهادِ بهبود',
            self::Criticism => 'انتقاد',
            self::Bug => 'گزارشِ مشکل',
        };
    }
}
