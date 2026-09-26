<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** تأییدِ دوباره‌ی رمز پیش از عملیاتِ حساس (مرحلهٔ ۵ · فاز ۱۰). */
class ConfirmPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['password' => ['required', 'string', 'max:255']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['password' => 'رمز عبور'];
    }
}
