<?php

namespace App\Http\Requests;

use App\Support\Phone;

/** ثبتِ درخواستِ بازیابیِ حساب با شماره‌ی تازه‌ی تأییدشده (مرحلهٔ ۹). */
class SubmitAccountRecoveryRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $digits = [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ];

        $this->merge(array_filter([
            'old_phone' => $this->has('old_phone') ? Phone::normalize((string) $this->input('old_phone')) : null,
            'new_phone' => $this->has('new_phone') ? Phone::normalize((string) $this->input('new_phone')) : null,
            'code' => $this->has('code') ? strtr((string) $this->input('code'), $digits) : null,
            'national_id' => $this->filled('national_id') ? strtr((string) $this->input('national_id'), $digits) : null,
        ], fn ($value) => $value !== null));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'old_phone' => ['required', 'regex:/^09\d{9}$/'],
            'new_phone' => ['required', 'regex:/^09\d{9}$/'],
            'code' => ['required', 'digits:6'],
            'national_id' => ['nullable', 'regex:/^\d{10}$/'],
            'explanation' => ['required', 'string', 'min:20', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'old_phone.regex' => 'شماره‌ی قبلی باید به شکل ۰۹xxxxxxxxx باشد.',
            'new_phone.regex' => 'شماره‌ی تازه باید به شکل ۰۹xxxxxxxxx باشد.',
            'national_id.regex' => 'کد ملی باید ۱۰ رقم باشد.',
            'explanation.min' => 'شرح را کامل‌تر بنویسید (دست‌کم ۲۰ نویسه): چه شد و چطور می‌توان مالکیتِ حساب را سنجید.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'old_phone' => 'شماره‌ی قبلی', 'new_phone' => 'شماره‌ی تازه', 'code' => 'کد تأیید',
            'national_id' => 'کد ملی', 'explanation' => 'شرح',
        ];
    }
}
