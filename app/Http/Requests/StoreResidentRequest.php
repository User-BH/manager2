<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * ساکن — همان قواعد برای ساخت و ویرایش.
 *
 * از `Api/ResidentController.php::validateData()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class StoreResidentRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            /*
             * یکتایی فقط میانِ کاربرانی که **از قبل به مجتمعی وصل‌اند** (R21).
             *
             * شماره‌ای که متعلق به کاربرِ ثبت‌نام‌کرده‌ی بدونِ مجتمع است باید از
             * اعتبارسنجی رد شود تا کنترلر بتواند برایش دعوت بفرستد. پیش از این
             * همین‌جا ۴۲۲ می‌گرفت و آن کاربر در بن‌بستِ دائمی می‌ماند.
             *
             * ساکنِ مجتمعِ دیگر همچنان رد می‌شود؛ بیرون‌کشیدنش کارِ این فرم نیست.
             */
            'phone' => [
                'required',
                'regex:/^09\d{9}$/',
                Rule::unique('users', 'phone')
                    ->whereNotNull('complex_id')
                    ->ignore($this->route('resident')?->id),
            ],
            'email' => ['nullable', 'email', Rule::unique('users', 'email')->ignore($this->route('resident')?->id)],
            'national_id' => ['nullable', 'string', 'max:20'],
            'role' => ['required', 'in:owner,tenant'],
            // exists خام به مجتمع محدود نیست و ComplexScope هم روی کوئریِ
            // اعتبارسنجی اعمال نمی‌شود؛ بدون این قید، شناسه‌ی واحدِ مجتمع
            // دیگری هم پذیرفته می‌شد.
            'unit_id' => [
                'nullable',
                Rule::exists('units', 'id')->where('complex_id', $this->currentComplexId()),
            ],
            // همان قاعده‌ی تغییر رمز در پروفایل؛ پیش از این min:6 بود و حساب‌هایی
            // که مدیر می‌ساخت می‌توانستند رمز بسیار ضعیف داشته باشند.
            'password' => [$this->route('resident') ? 'nullable' : 'required', 'nullable', Password::min(8)->letters()->numbers()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'شماره تلفن همراه باید به شکل ۰۹xxxxxxxxx باشد.',
            'phone.unique' => 'این شماره تلفن قبلا ثبت شده است.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'نام', 'email' => 'ایمیل', 'phone' => 'شماره تلفن',
            'role' => 'نقش', 'password' => 'رمز عبور',
        ];
    }
}
