<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rules\Password;

/**
 * تغییرِ رمزِ خودِ کاربر.
 *
 * از `Api/ProfileController.php::updatePassword()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class UpdatePasswordRequest extends BaseFormRequest
{
    /**
     * فقط حسابِ رمزدار (۲۰۲۶-۱۰-۳۰): دو مدیرِ سامانه و حساب‌های نمونه‌ی Preview. کاربرانِ سایت رمز ندارند — پیش از
     * اعتبارسنجی ۴۰۳، تا پاسخِ «رمزِ ضعیف» هم وانمود نکند که چنین مسیری برایشان هست.
     */
    public function authorize(): bool
    {
        return $this->user()?->usesPassword() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            // رمز قوی: حداقل ۸ نویسه، دست‌کم یک حرف و یک رقم — همان قاعده‌ی کلاینت
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(8)->letters()->numbers()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.different' => 'رمز جدید باید با رمز فعلی متفاوت باشد.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'current_password' => 'رمز عبور فعلی',
            'password' => 'رمز عبور جدید',
        ];
    }
}
