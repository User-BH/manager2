<?php

namespace App\Http\Requests;

/** گامِ دومِ تغییرِ شماره: فقط کدِ پیامکیِ شماره‌ی تازه (مرحلهٔ ۹). */
class ConfirmPhoneChangeRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtr((string) $this->input('code'), [
                '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            ])]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'digits:6'],
            'phone' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['code' => 'کد تأیید'];
    }
}
