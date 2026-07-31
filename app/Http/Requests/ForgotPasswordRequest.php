<?php

namespace App\Http\Requests;

/**
 * درخواستِ بازیابیِ رمز با شماره‌ی موبایل.
 *
 * از `Api/AuthController.php::forgotPassword()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class ForgotPasswordRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['phone' => 'شماره موبایل'];
    }
}
