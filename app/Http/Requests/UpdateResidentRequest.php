<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * ویرایشِ ساکن توسطِ مدیر (مرحلهٔ ۹ · فاز ۶).
 *
 * 🔴 شماره و رمز **مالِ صاحبِ حساب‌اند**، نه مدیر. پیش از این مدیر می‌توانست
 * شماره‌ی ساکن را به شماره‌ی خودش و رمزش را به رمزِ دلخواه عوض کند — یعنی
 * تصاحبِ کاملِ حساب (کدِ OTP ِ ورود هم به شماره‌ی تازه می‌رفت). با F4 حساب پس
 * از رفتنِ ساکن هم می‌ماند، پس این تصاحب دائمی می‌شد. هر دو `prohibited`اند.
 *
 * نقش، واحد (اجباری؛ F6) و دو فیلدِ اختیاریِ ثبتِ مدیر همچنان در دستِ اوست.
 */
class UpdateResidentRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            /*
             * ایمیل و کدِ ملی مالِ **حساب** است و حساب بینِ مجتمع‌ها یکی است (مرحلهٔ ۱۰ · فاز ۷؛ بندهای ۲، ۴ و ۵):
             * پیش از این مدیرِ هر مجتمعی که شخص عضوش بود آن‌ها را بازنویسی می‌کرد — و کدِ ملی را با قاعده‌ای
             * سست‌تر از پروفایل (`max:20` در برابرِ دقیقاً ده رقم). حالا فقط خودِ کاربر در پروفایلش.
             */
            'email' => ['prohibited'],
            'national_id' => ['prohibited'],
            'role' => ['required', 'in:owner,tenant'],
            'unit_id' => [
                'required',
                Rule::exists('units', 'id')
                    ->where('complex_id', $this->currentComplexId())
                    ->whereNull('deleted_at'),
            ],
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
            'unit_id.required' => 'برای مالک و مستأجر انتخابِ واحد لازم است.',
            'phone.prohibited' => 'شماره‌ی حساب را فقط خودِ کاربر دارد؛ مدیر آن را عوض نمی‌کند.',
            'email.prohibited' => 'ایمیلِ حساب را خودِ کاربر در پروفایلش تعیین می‌کند.',
            'national_id.prohibited' => 'کدِ ملیِ حساب را خودِ کاربر در پروفایلش تعیین می‌کند.',
            'password.prohibited' => 'رمزِ حساب را فقط خودِ کاربر عوض می‌کند.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'نام', 'email' => 'ایمیل', 'role' => 'نقش', 'unit_id' => 'واحد'];
    }
}
