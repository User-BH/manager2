<?php

namespace App\Http\Requests;

use App\Support\Phone;

/**
 * گامِ اولِ تغییرِ شماره توسطِ خودِ کاربر (مرحلهٔ ۹). فقط شماره‌ی تازه؛ هر شناسه‌ی
 * دیگری (`user_id` و …) رد می‌شود تا روشن باشد این مسیر فقط برای حسابِ خودِ
 * درخواست‌کننده است.
 */
class StartPhoneChangeRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge(['phone' => Phone::normalize((string) $this->input('phone'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'regex:/^09\d{9}$/'],
            'user_id' => ['prohibited'],
            'account_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'شماره تلفن همراه باید به شکل ۰۹xxxxxxxxx باشد.',
            'user_id.prohibited' => 'این مسیر فقط شماره‌ی حسابِ خودِ شما را عوض می‌کند.',
            'account_id.prohibited' => 'این مسیر فقط شماره‌ی حسابِ خودِ شما را عوض می‌کند.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['phone' => 'شماره‌ی تازه'];
    }
}
