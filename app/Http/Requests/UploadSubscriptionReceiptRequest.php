<?php

namespace App\Http\Requests;

use App\Enums\AccountState;
use App\Enums\ComplexType;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * رسیدِ خریدِ اشتراک که مدیرِ مجتمع می‌فرستد.
 *
 * از `Api/SubscriptionController.php::uploadReceipt()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class UploadSubscriptionReceiptRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plan' => ['required', 'string'],
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:300'],
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],

            /*
             * خریدارِ «حالتِ اولیه» هنوز مجتمعی ندارد، پس باید نامش را بدهد
             * تا همین خرید مجتمع را بسازد (R21). برای مدیرِ فعلی بی‌معناست و
             * نادیده گرفته می‌شود.
             */
            'complex_name' => [
                $this->needsComplex() ? 'required' : 'nullable',
                'string', 'max:150',
            ],
            'complex_address' => ['nullable', 'string', 'max:255'],

            /*
             * نوعِ مجتمع، فقط در همان خریدی که مجتمع را می‌سازد (R67).
             *
             * ⚠️ برای مدیرِ فعلی عمداً نادیده گرفته می‌شود: تغییرِ نوع پس از
             * ساخت فقط کارِ ادمینِ کل است، چون تعرفه‌ی پکیج به آن گره خورده
             * و تغییرِ آزادانه‌اش یعنی مدیر بتواند با یک سوییچ تعرفه‌ی
             * ارزان‌تر را بگیرد.
             *
             * ─── 🔴 چرا `nullable` و نه `required` ─────────────────────────
             * قاعده‌ی خودِ پروژه (`docs/API_COMPATIBILITY.md`): افزودنِ فیلدِ
             * **اجباری** به بدنه‌ی درخواست تغییرِ شکننده است و تبِ بازِ
             * کاربر را می‌شکند — دقیقاً وسطِ خرید.
             *
             * پس گامِ Expand: فیلد اختیاری است و نبودنش «مسکونی» معنی
             * می‌دهد. فرانت همیشه می‌فرستدش. گامِ Contract (اجباری‌کردن)
             * پس از یک چرخه‌ی استقرار انجام می‌شود و در
             * `docs/COMPLEX_TYPES.md` ثبت شده.
             */
            'complex_type' => ['nullable', Rule::in(ComplexType::values())],
        ];
    }

    /**
     * آیا این خرید باید مجتمع را هم بسازد؟
     *
     * از واقعیت مشتق می‌شود (وصل نبودن به مجتمع)، مثل خودِ `AccountState`.
     */
    private function needsComplex(): bool
    {
        $user = $this->user();

        return $user instanceof User && AccountState::of($user) === AccountState::Initial;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'complex_name.required' => 'برای ساخت مجتمع، نام آن را وارد کنید.',
            'receipt.mimes' => 'فایل رسید باید تصویر (jpg/png) یا PDF باشد.',
            'receipt.max' => 'حجم فایل رسید نباید از ۴ مگابایت بیشتر باشد.',
            'paid_on.before_or_equal' => 'تاریخ واریز نمی‌تواند در آینده باشد.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'plan' => 'پلن', 'paid_on' => 'تاریخ واریز', 'receipt' => 'فایل رسید',
        ];
    }
}
