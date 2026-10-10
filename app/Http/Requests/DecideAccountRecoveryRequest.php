<?php

namespace App\Http\Requests;

/** تأییدِ بازیابیِ حساب توسطِ ادمینِ کل؛ یادداشت اختیاری (مرحلهٔ ۹). */
class DecideAccountRecoveryRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['note' => ['nullable', 'string', 'max:500']];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['note' => 'یادداشت'];
    }
}
