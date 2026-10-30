<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * تأییدِ دوباره پیش از عملیاتِ حساس (مرحلهٔ ۵ · فاز ۱۰). از ۲۰۲۶-۱۰-۳۰ دو شکل دارد: مدیرِ سامانه با رمز، بقیه — که رمز
 * ندارند — با کدِ پیامکیِ شماره‌ی خودشان (`profile/confirm-code`). کدام لازم است را کنترلر از نقشِ حساب می‌گوید، نه درخواست.
 */
class ConfirmPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'password' => ['required_without:code', 'nullable', 'string', 'max:255'],
            'code' => ['required_without:password', 'nullable', 'string', 'max:16'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['password' => 'رمز عبور', 'code' => 'کد تأیید'];
    }
}
