<?php

namespace App\Http\Requests;

/** ردِ بازیابیِ حساب؛ دلیل الزامی است تا در حسابرسی بماند (مرحلهٔ ۹). */
class RejectAccountRecoveryRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['note' => ['required', 'string', 'min:5', 'max:500']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['note.required' => 'دلیلِ رد را بنویسید.'];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['note' => 'دلیل'];
    }
}
