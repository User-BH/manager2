<?php

namespace App\Http\Requests;

/**
 * کدِ شش‌رقمیِ برنامه‌ی Authenticator: تأییدِ کلیدِ تازه، یا کدِ فعلی پیش از ساختِ دوباره‌ی کدهای بازیابی (۲۰۲۶-۱۰-۲۵).
 */
class TwoFactorCodeRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:16'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['code' => 'کدِ برنامه'];
    }
}
