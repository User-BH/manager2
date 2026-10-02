<?php

namespace App\Http\Requests;

use App\Enums\FeedbackStatus;
use Illuminate\Validation\Rule;

/** تغییرِ وضعیتِ یک بازخورد: تازه، خوانده‌شده یا بایگانی (مرحلهٔ ۷ · فاز ۴). */
class UpdateFeedbackStatusRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(FeedbackStatus::class)],
        ];
    }

    public function attributes(): array
    {
        return ['status' => 'وضعیت'];
    }
}
