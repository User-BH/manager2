<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rules\Password;

/**
 * ثبتِ رمزِ تازه پس از تاییدِ کد.
 *
 * از `Api/AuthController.php::resetPassword()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class ResetPasswordRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['password' => 'رمز عبور'];
    }
}
