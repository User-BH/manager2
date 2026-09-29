<?php

namespace App\Http\Requests;

/** گزارشِ پیام به مدیریت (مرحلهٔ ۶ · فاز ۱۴). */
class ReportMessageRequest extends BaseFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:300']];
    }
}
