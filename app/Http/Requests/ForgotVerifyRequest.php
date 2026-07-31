<?php

namespace App\Http\Requests;

/**
 * تاییدِ کدِ بازیابیِ رمز.
 *
 * از `Api/AuthController.php::forgotVerify()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class ForgotVerifyRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['code' => ['required', 'string']];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['code' => 'کد'];
    }
}
