<?php

namespace App\Http\Requests;

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
            'name' => ['required', 'string', 'max:255'],
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'نام',
            'phone' => 'شماره تلفن',
            'password' => 'رمز عبور',
        ];
    }
}
