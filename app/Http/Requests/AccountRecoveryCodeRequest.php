<?php

namespace App\Http\Requests;

use App\Support\Phone;

/** کدِ پیامکی برای اثباتِ شماره‌ی تازه در بازیابیِ حساب (مرحلهٔ ۹). */
class AccountRecoveryCodeRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('new_phone')) {
            $this->merge(['new_phone' => Phone::normalize((string) $this->input('new_phone'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['new_phone' => ['required', 'regex:/^09\d{9}$/']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['new_phone.regex' => 'شماره تلفن همراه باید به شکل ۰۹xxxxxxxxx باشد.'];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['new_phone' => 'شماره‌ی تازه'];
    }
}
