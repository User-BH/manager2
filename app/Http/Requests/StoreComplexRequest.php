<?php

namespace App\Http\Requests;

use App\Enums\ComplexType;
use App\Support\Phone;
use Illuminate\Validation\Rule;

/**
 * ساختِ مجتمعِ تازه (ادمینِ کل).
 *
 * از `Api/System/ComplexController.php::store()` بیرون کشیده شد (R9b). از مرحلهٔ ۹ ·
 * فاز ۶ مدیر با دعوتِ امن می‌آید، نه با حسابی که ادمینِ کل می‌سازد.
 */
class StoreComplexRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('admin_phone')) {
            $this->merge(['admin_phone' => Phone::normalize((string) $this->input('admin_phone'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            /*
             * نوعِ مجتمع (R67) — تعرفه‌ی پکیج‌های اشتراک به آن وابسته است.
             *
             * ⚠️ `nullable` طبقِ همان قاعده‌ی سازگاری: فیلدِ اجباریِ تازه
             * تغییرِ شکننده است. خالی یعنی مسکونی، و فرمِ پنل همیشه پرش
             * می‌کند.
             */
            'type' => ['required', Rule::in(ComplexType::values())],
            'address' => ['nullable', 'string', 'max:255'],
            // ورود سامانه با شماره تلفن است، پس مدیر مجتمع حتماً باید شماره
            // داشته باشد؛ نسخه‌ی قبلی فقط ایمیل می‌گرفت و حساب ساخته‌شده
            // عملاً قابل ورود نبود.
            'admin_phone' => ['required', 'regex:/^09\d{9}$/'],
            /*
             * 🔴 مرحلهٔ ۹ · فاز ۶ (تصمیمِ کارفرما): ادمینِ کل دیگر **حساب نمی‌سازد**.
             * پیش از این نام و رمزِ مدیر را خودش تعیین می‌کرد و حساب بی OTP
             * ساخته می‌شد (همان F5). حالا برای شماره‌ی مدیر دعوتِ امن صادر
             * می‌شود؛ نام و رمز را صاحبِ شماره خودش هنگامِ ثبت‌نام می‌گذارد.
             */
            'admin_name' => ['prohibited'],
            'admin_email' => ['prohibited'],
            'admin_password' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'admin_phone.regex' => 'شماره تلفن همراه باید به شکل ۰۹xxxxxxxxx باشد.',
            'admin_name.prohibited' => 'نامِ مدیر را خودش هنگامِ ثبت‌نام وارد می‌کند.',
            'admin_password.prohibited' => 'رمزِ مدیر را خودش هنگامِ ثبت‌نام انتخاب می‌کند.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'نام مجتمع',
            'type' => 'نوع مجتمع',
            'admin_phone' => 'شماره مدیر',
        ];
    }
}
