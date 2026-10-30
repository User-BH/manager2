<?php

namespace App\Http\Requests;

/**
 * ورودِ دو مدیرِ سامانه از `/auth/admin` (۲۰۲۶-۱۰-۳۰): نام کاربری + رمز. قالبِ نام کاربری عمداً این‌جا سنجیده نمی‌شود تا
 * پاسخِ «نادرست» برای هر ورودی یکی بماند.
 */
class AdminLoginRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['username' => 'نام کاربری', 'password' => 'رمز عبور'];
    }
}
