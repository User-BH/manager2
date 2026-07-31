<?php

namespace App\Http\Requests;

/**
 * گامِ اولِ ورود: شماره و رمز. تاییدِ کد در گامِ دوم است.
 *
 * از `Api/AuthController.php::login()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class LoginRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['phone' => 'شماره تلفن', 'password' => 'رمز عبور'];
    }
}
