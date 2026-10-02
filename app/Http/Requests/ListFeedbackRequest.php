<?php

namespace App\Http\Requests;

use App\Enums\FeedbackStatus;
use Illuminate\Validation\Rule;

/** صافیِ صفِ بازخوردها برای ادمینِ کل (مرحلهٔ ۷ · فاز ۴). */
class ListFeedbackRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(FeedbackStatus::class)],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
