<?php

namespace App\Http\Requests;

/**
 * گامِ دومِ ثبت‌نام — تنها جایی که حساب واقعاً ساخته می‌شود.
 *
 * از `Api/AuthController.php::registerVerify()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class RegisterVerifyRequest extends BaseFormRequest
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
