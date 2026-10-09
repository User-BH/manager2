<?php

namespace App\Http\Requests;

use App\Support\Phone;
use Illuminate\Validation\Rule;

/**
 * «ساکن جدید» = **دعوتِ امن به یک شماره** (مرحلهٔ ۹ · فاز ۶، F5 و F6).
 *
 * پیش از این مدیر برای شماره‌ی بی‌حساب حساب می‌ساخت — با نامی که خودش می‌نوشت
 * و رمزی که خودش انتخاب می‌کرد، بی OTP. حالا فقط شماره، نقش و واحد می‌گیرد؛
 * حساب، نام و رمز را صاحبِ شماره خودش با OTP می‌سازد (`Membership::invite`).
 *
 * `name` و `password` دیگر پذیرفته نمی‌شوند (`prohibited`)، نه بی‌صدا نادیده:
 * کلاینتِ کهنه‌ای که رمز بفرستد باید بفهمد رمزی ساخته نشد.
 */
class StoreResidentRequest extends BaseFormRequest
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
            'role' => ['required', 'in:owner,tenant'],
            // F6: ساکن بی‌واحد نیست؛ واحدِ همین مجتمع و حذف‌نشده
            'unit_id' => [
                'required',
                Rule::exists('units', 'id')
                    ->where('complex_id', $this->currentComplexId())
                    ->whereNull('deleted_at'),
            ],
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
            'unit_id.required' => 'برای مالک و مستأجر انتخابِ واحد لازم است.',
            'name.prohibited' => 'نام را خودِ کاربر هنگامِ ثبت‌نام وارد می‌کند.',
            'password.prohibited' => 'رمز را خودِ کاربر هنگامِ ثبت‌نام انتخاب می‌کند.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['phone' => 'شماره تلفن', 'role' => 'نقش', 'unit_id' => 'واحد'];
    }
}
