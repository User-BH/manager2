<?php

namespace App\Http\Requests;

use App\Support\Jalali;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

/**
 * بازه‌ی یک پرسشِ تقویم (۱۳/۷): دو تاریخِ میلادیِ «Y-m-d»، حداکثر {@see self::MAX_DAYS} روز — شش هفته‌ی یک ماه، با حاشیه.
 * سقف جلوی پرسشی را می‌گیرد که یادآورهای تکرارشونده را برای سال‌ها باز کند.
 */
class ReminderRangeRequest extends BaseFormRequest
{
    public const MAX_DAYS = 62;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $days = CarbonImmutable::parse((string) $this->input('from'))->diffInDays(CarbonImmutable::parse((string) $this->input('to')));
            if ($days > self::MAX_DAYS) {
                $validator->errors()->add('to', 'بازه‌ی تقویم حداکثر '.Jalali::digits(self::MAX_DAYS).' روز است.');
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['from' => 'آغازِ بازه', 'to' => 'پایانِ بازه'];
    }
}
