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

    /** مرحلهٔ ۸ · فاز ۵: «سایر» در فهرستِ «بررسی جدید» بود و این‌جا نبود */
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            // برچسب‌ها همان فهرستِ «بررسی جدید»؛ مقدارها دست نخوردند (پیام‌های ذخیره‌شده)
            self::Feedback => 'بازخورد درباره‌ی تجربه‌ی کاربری',
            self::Feature => 'درخواستِ قابلیت',
            self::Improvement => 'پیشنهاد',
            self::Criticism => 'انتقاد',
            self::Bug => 'گزارشِ مشکل',
            self::Other => 'سایر',
        };
    }
}
