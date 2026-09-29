<?php

namespace App\Http\Requests;

use App\Models\MessageReport;
use Illuminate\Validation\Rule;

/** رسیدگیِ مدیر به گزارش: پنهان‌کردنِ پیام یا کنارگذاشتنِ گزارش (مرحلهٔ ۶ · فاز ۱۴). */
class ResolveReportRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['resolution' => ['required', Rule::in([MessageReport::HIDE, MessageReport::DISMISS])]];
    }
}
