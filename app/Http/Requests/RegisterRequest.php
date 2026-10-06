<?php

namespace App\Http\Requests;

use App\Support\PersianName;
use Illuminate\Validation\Rules\Password;

/**
 * گامِ اولِ ثبت‌نام. حساب اینجا ساخته **نمی‌شود**؛ فقط کد فرستاده می‌شود.
 *
 * از `Api/AuthController.php::register()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class RegisterRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // نام و نام خانوادگی جدا و فقط فارسی (مرحلهٔ ۹ · فاز ۳؛ تصمیمِ ۱۲)
            'first_name' => PersianName::rules(),
            'last_name' => PersianName::rules(),
            'phone' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'accept_terms' => ['required', 'accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'accept_terms.required' => 'برای ادامه باید قوانین و مقررات را بپذیرید.',
            'accept_terms.accepted' => 'برای ادامه باید قوانین و مقررات را بپذیرید.',
            'first_name.regex' => 'نام فقط با حروف فارسی نوشته شود.',
            'last_name.regex' => 'نام خانوادگی فقط با حروف فارسی نوشته شود.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'first_name' => 'نام',
            'last_name' => 'نام خانوادگی',
            'phone' => 'شماره تلفن',
            'password' => 'رمز عبور',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'first_name' => PersianName::normalize($this->input('first_name')),
            'last_name' => PersianName::normalize($this->input('last_name')),
        ]);
    }
}
