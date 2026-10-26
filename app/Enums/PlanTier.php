<?php

namespace App\Enums;

/**
 * سطحِ پلن — پایه، حرفه‌ای، پیشرفته (مرحلهٔ ۱۳ · فاز ۶؛ ایرادات ۱۰، بندهای ۱۱ و ۱۵؛ پرسشِ ت-۱۲).
 *
 * سطح جدا از نوعِ مجتمع و جدا از مدت است: «پلن» = یک سطح برای یک نوع (یا هر دو)، و قیمتِ هر مدت ردیفِ خودش را در
 * `plan_prices` دارد. نامِ نمایشیِ پلن را سوپر ادمین می‌گذارد؛ سطح canonical است و کد به آن تکیه می‌کند (کارت‌ها،
 * «پیشرفته» ِ دستیار در ۱۳/۸).
 *
 * ⚠️ داده‌ی پیشین «سازمانی» نامیده می‌شد (`business`)؛ همان سطحِ سوم است و مهاجرت آن را `advanced` می‌گذارد.
 */
enum PlanTier: string
{
    case Basic = 'basic';

    case Professional = 'professional';

    case Advanced = 'advanced';

    /** مدت‌های قابلِ خرید، به ماه (بندِ ۱۶). قیمتِ هر کدام را سوپر ادمین می‌گذارد؛ مدتِ بی‌قیمت خریدنی نیست. */
    public const DURATIONS = [1, 3, 6, 12, 18, 24];

    public function label(): string
    {
        return match ($this) {
            self::Basic => 'پایه',
            self::Professional => 'حرفه‌ای',
            self::Advanced => 'پیشرفته',
        };
    }

    /** ترتیبِ کارت‌ها: پایه، حرفه‌ای، پیشرفته */
    public function rank(): int
    {
        return match ($this) {
            self::Basic => 1,
            self::Professional => 2,
            self::Advanced => 3,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
