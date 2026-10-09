<?php

namespace App\Http\Requests;

use App\Support\Phone;

/**
 * «مدیر جدید» = **دعوتِ امن با نقشِ مدیر** (مرحلهٔ ۹ · فاز ۶، F5).
 *
 * پیش از این مدیر برای شماره حساب می‌ساخت و رمزش را خودش تعیین می‌کرد. حالا
 * فقط شماره؛ صاحبِ شماره خودش با OTP ثبت‌نام می‌کند و دعوت را می‌پذیرد.
 */
class StoreManagerRequest extends BaseFormRequest
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
            'name' => ['prohibited'],
            'password' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'شماره تلفن همراه باید به شکل ۰۹xxxxxxxxx باشد.',
            'name.prohibited' => 'نام را خودِ کاربر هنگامِ ثبت‌نام وارد می‌کند.',
            'password.prohibited' => 'رمز را خودِ کاربر هنگامِ ثبت‌نام انتخاب می‌کند.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['phone' => 'شماره تلفن'];
    }
}
