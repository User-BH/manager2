<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * ویرایشِ پروفایلِ خودِ کاربر.
 *
 * از `Api/ProfileController.php::update()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class UpdateProfileRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'regex:/^[\p{L}\s\x{200c}\'\-]+$/u'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users')->ignore($this->user()?->id)],
            // کد ملی: دقیقاً ۱۰ رقم (رقم کنترلی سمت کلاینت بررسی می‌شود)
            'national_id' => ['nullable', 'regex:/^\d{10}$/'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            // موبایل یا ثابت: ۱۱ رقم با شروع ۰
            'emergency_phone' => ['nullable', 'regex:/^0\d{10}$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'نام فقط می‌تواند شامل حروف باشد.',
            'national_id.regex' => 'کد ملی باید ۱۰ رقم باشد.',
            'emergency_phone.regex' => 'شماره تماس معتبر نیست.',
            'birth_date.before_or_equal' => 'تاریخ تولد نمی‌تواند در آینده باشد.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'نام',
            'email' => 'ایمیل',
            'national_id' => 'کد ملی',
            'birth_date' => 'تاریخ تولد',
            'emergency_phone' => 'شماره اضطراری',
            'address' => 'نشانی',
            'bio' => 'درباره من',
        ];
    }

    /**
     * ارقامِ فارسی/عربی پیش از رسیدن به regex به لاتین تبدیل می‌شوند.
     *
     * ─── چرا اینجا و نه در کنترلر ───────────────────────────────────────────
     * پیش از این کنترلر با `$request->merge()` این کار را می‌کرد. با انتقالِ
     * قواعد به FormRequest، اعتبارسنجی **پیش از** بدنه‌ی کنترلر اجرا می‌شود،
     * پس آن نرمال‌سازی دیگر به‌موقع نمی‌رسید و کاربری که کدِ ملی را با ارقامِ
     * فارسی می‌فرستاد ۴۲۲ می‌گرفت. `prepareForValidation` دقیقاً برای همین
     * لحظه ساخته شده.
     *
     * دلیلِ وجودِ خودِ نرمال‌سازی هم پابرجاست: درخواستِ مستقیمِ API هم باید
     * مثل کلاینت پاک باشد، نه اینکه به پاک‌سازیِ سمتِ مرورگر تکیه کنیم.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'national_id' => self::latinDigits($this->input('national_id')),
            'emergency_phone' => self::latinDigits($this->input('emergency_phone')),
        ]);
    }

    private static function latinDigits(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $from = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $to = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        return str_replace($from, $to, $value);
    }
}
