<?php

namespace App\Http\Requests;

/**
 * گامِ دومِ ورود: کدِ یک‌بارمصرف.
 *
 * از `Api/AuthController.php::loginVerify()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class LoginVerifyRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['code' => 'کد'];
    }
}
