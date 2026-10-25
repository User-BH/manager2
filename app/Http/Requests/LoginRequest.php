<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * گامِ اولِ ورود: شناسه و رمز. تاییدِ کد در گامِ دوم است.
 *
 * از `Api/AuthController.php::login()` بیرون کشیده شد (R9b).
 *
 * شناسه دو نوع دارد و نوعش را **خودِ درخواست** می‌گوید، نه شکلِ ورودی (مرحلهٔ ۱۳ · فاز ۱):
 * - `phone` (پیش‌فرض، همان قرارِ قبلی): کاربرِ واقعی با شماره‌ی موبایل؛
 * - `username`: فقط سوپر ادمین و حساب‌های آزمایشیِ Preview. قالبش در کنترلر سنجیده نمی‌شود تا پاسخِ نام کاربریِ
 *   بدقالب با «نام کاربری یا رمزِ نادرست» یکی بماند و چیزی درباره‌ی وجودِ حساب نگوید.
 */
class LoginRequest extends BaseFormRequest
{
    public const PHONE = 'phone';

    public const USERNAME = 'username';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // قاعده‌ها ایستا‌اند (نه وابسته به ورودی): مولدِ OpenAPI آن‌ها را بی درخواستِ واقعی می‌خواند
        return [
            'identifier_type' => ['sometimes', 'string', Rule::in([self::PHONE, self::USERNAME])],
            'phone' => ['required_unless:identifier_type,'.self::USERNAME, 'nullable', 'string'],
            'username' => ['required_if:identifier_type,'.self::USERNAME, 'nullable', 'string', 'max:64'],
            'password' => ['required', 'string'],
        ];
    }

    public function byUsername(): bool
    {
        return $this->input('identifier_type') === self::USERNAME;
    }

    /** کلیدِ خطای شناسه: همان فیلدی که کاربر پر کرده */
    public function identifierField(): string
    {
        return $this->byUsername() ? 'username' : 'phone';
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['phone' => 'شماره تلفن', 'username' => 'نام کاربری', 'password' => 'رمز عبور'];
    }
}
