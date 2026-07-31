<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * افزودنِ مدیر به مجتمع.
 *
 * از `Api/ManagerController.php::store()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class StoreManagerRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'regex:/^09\d{9}$/', Rule::unique('users', 'phone')],
            // مدیر مجتمع دسترسی مالی کامل دارد؛ رمزش نباید ضعیف‌تر از رمز پروفایل باشد.
            'password' => ['required', Password::min(8)->letters()->numbers()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'شماره تلفن همراه باید به شکل ۰۹xxxxxxxxx باشد.',
            'phone.unique' => 'این شماره قبلا ثبت شده است.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'نام', 'phone' => 'شماره تلفن', 'password' => 'رمز عبور'];
    }
}
