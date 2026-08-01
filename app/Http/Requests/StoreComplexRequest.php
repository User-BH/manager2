<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * ساختِ مجتمعِ تازه (ادمینِ کل).
 *
 * از `Api/System/ComplexController.php::store()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class StoreComplexRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'admin_name' => ['required', 'string', 'max:120'],
            // ورود سامانه با شماره تلفن است، پس مدیر مجتمع حتماً باید شماره
            // داشته باشد؛ نسخه‌ی قبلی فقط ایمیل می‌گرفت و حساب ساخته‌شده
            // عملاً قابل ورود نبود.
            'admin_phone' => ['required', 'regex:/^09\d{9}$/', Rule::unique('users', 'phone')],
            'admin_email' => ['nullable', 'email', Rule::unique('users', 'email')],
            /*
             * همان سیاستِ رمزِ بقیه‌ی سامانه (R18).
             *
             * پیش از این `min:6` بدونِ هیچ قیدی بود — یعنی سست‌ترین قاعده‌ی
             * پروژه دقیقاً روی پرقدرت‌ترین حسابِ داخلِ یک مجتمع اعمال می‌شد.
             * حسابِ مدیرِ مجتمع به قبض، پرداخت و همه‌ی ساکنین دسترسی دارد؛
             * «123456» برایش قابل قبول نیست.
             */
            'admin_password' => ['required', Password::min(8)->letters()->numbers()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'admin_phone.regex' => 'شماره تلفن همراه باید به شکل ۰۹xxxxxxxxx باشد.',
            'admin_phone.unique' => 'این شماره تلفن قبلا ثبت شده است.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'نام مجتمع',
            'admin_name' => 'نام مدیر',
            'admin_phone' => 'شماره مدیر',
            'admin_email' => 'ایمیل مدیر',
            'admin_password' => 'رمز عبور مدیر',
        ];
    }
}
