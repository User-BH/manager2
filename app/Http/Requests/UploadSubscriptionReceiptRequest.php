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
             * ─── 🔴 چرا حالا `required` شد (فاز ۱) ───────────────────────
             * پیش از این `nullable` بود، با این استدلال که افزودنِ فیلدِ
             * اجباری تغییرِ شکننده است و «فرانت همیشه می‌فرستدش».
             *
             * ⚠️ **آن جمله غلط بود.** بازبینیِ فاز ۱ نشان داد هیچ فرمی در
             * فرانت این فیلد را نمی‌فرستد — در واقع هیچ فرمی اصلاً مجتمع
             * نمی‌سازد؛ این مسیر امروز فقط از سمتِ API زنده است.
             *
             * یعنی نتیجه‌ی عملیِ آن پیش‌فرض این بود: **هر مجتمعی که ساخته
             * می‌شد بی‌صدا «مسکونی» ثبت می‌شد**، حتی اگر تجاری بود — و
             * تعرفه‌ی اشتراکش هم به همان گره می‌خورد.
             *
             * چون هیچ کلاینتی این فیلد را نمی‌فرستد، اجباری‌کردنش هیچ تبِ
             * بازی را نمی‌شکند. گامِ Contract همین‌جا انجام شد.
             *
             * ⚠️ شرط عمدی است: نوع فقط وقتی لازم است که همین خرید مجتمع را
             * **بسازد**. برای مدیرِ فعلی نادیده گرفته می‌شود.
             */
            'complex_type' => [
                $this->needsComplex() ? 'required' : 'nullable',
                Rule::in(ComplexType::values()),
            ],
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
            /*
             * 🔴 پیامِ پیش‌فرض («وارد کردن complex type الزامی است») برای
             * کاربر بی‌معنی است. و مهم‌تر: باید بگوید **چرا** می‌پرسیم،
             * چون تعرفه‌ی اشتراک به همین پاسخ گره می‌خورد.
             */
            'complex_type.required' => 'نوع مجتمع (مسکونی یا تجاری) را انتخاب کنید؛ تعرفه‌ی اشتراک به آن بستگی دارد.',
            'complex_type.in' => 'نوع مجتمع باید مسکونی یا تجاری باشد.',
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
