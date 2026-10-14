<?php

namespace App\Http\Requests;

use App\Support\Digits;
use App\Support\PersianName;
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
            // نام و نام خانوادگی جدا (مرحلهٔ ۹ · فاز ۳)؛ `name` را مدل از این دو می‌سازد
            // و دیگر از درخواست پذیرفته نمی‌شود — وگرنه دو منبعِ ناسازگار
            'first_name' => PersianName::rules(),
            'last_name' => PersianName::rules(),
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users')->ignore($this->user()?->id)],
            // کد ملی: دقیقاً ۱۰ رقم (رقم کنترلی سمت کلاینت بررسی می‌شود)
            'national_id' => ['nullable', 'regex:/^\d{10}$/'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            // موبایل یا ثابت: ۱۱ رقم با شروع ۰
            'emergency_phone' => ['nullable', 'regex:/^0\d{10}$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:500'],
            // شماره و رمز مسیرهای امنِ خودشان را دارند (کدِ پیامکی / رمزِ فعلی) — مرحلهٔ ۹
            'phone' => ['prohibited'],
            'password' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'first_name.regex' => 'نام فقط با حروف فارسی نوشته شود.',
            'last_name.regex' => 'نام خانوادگی فقط با حروف فارسی نوشته شود.',
            'national_id.regex' => 'کد ملی باید ۱۰ رقم باشد.',
            'emergency_phone.regex' => 'شماره تماس معتبر نیست.',
            'birth_date.before_or_equal' => 'تاریخ تولد نمی‌تواند در آینده باشد.',
            'phone.prohibited' => 'شماره را از «تغییرِ شماره» و با کدِ پیامکی عوض کنید.',
            'password.prohibited' => 'رمز را از «تغییر رمز عبور» و با رمزِ فعلی عوض کنید.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'first_name' => 'نام',
            'last_name' => 'نام خانوادگی',
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
            'first_name' => PersianName::normalize($this->input('first_name')),
            'last_name' => PersianName::normalize($this->input('last_name')),
            'national_id' => Digits::toLatin($this->input('national_id')),
            'emergency_phone' => Digits::toLatin($this->input('emergency_phone')),
        ]);
    }
}
