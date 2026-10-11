<?php

namespace App\Http\Requests;

/**
 * رفتن به زمینه‌ی مجتمعِ دیگر (مرحلهٔ ۱۰ · فاز ۳). شناسه فقط «کدام» را می‌گوید؛
 * اجازه را عضویتِ فعالِ خودِ حساب در همان مجتمع می‌دهد، نه این عدد.
 */
class SwitchContextRequest extends BaseFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['complex_id' => ['required', 'integer', 'min:1']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['complex_id' => 'مجتمع'];
    }
}
