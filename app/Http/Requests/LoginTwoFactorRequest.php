<?php

namespace App\Http\Requests;

/**
 * گامِ دومِ ورودِ سوپر ادمین: کدِ برنامه‌ی Authenticator **یا** یک کدِ بازیابیِ یک‌بارمصرف (۲۰۲۶-۱۰-۲۵).
 */
class LoginTwoFactorRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:16', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['code' => 'کدِ برنامه', 'recovery_code' => 'کدِ بازیابی'];
    }
}
