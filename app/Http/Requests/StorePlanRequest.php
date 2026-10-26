<?php

namespace App\Http\Requests;

use App\Enums\ComplexType;
use App\Enums\PlanTier;
use App\Models\Plan;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * پکیجِ اشتراک — همان قواعد برای ساخت و ویرایش.
 *
 * از `Api/System/PlanController.php::validatePlan()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class StorePlanRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/', Rule::unique('plans', 'slug')->ignore($this->route('plan')?->id)],
            /*
             * نوعِ مجتمعی که این پکیج برایش است (R67).
             *
             * ⚠️ `nullable` عمدی است و معنی‌دار: خالی یعنی «برای هر دو
             * نوع». همه‌ی پکیج‌های موجود در همین حالت‌اند تا ادمین تعرفه‌ی
             * تجاری را جدا تعریف کند.
             */
            'complex_type' => ['nullable', Rule::in(ComplexType::values())],
            /*
             * ۱۳/۶ (ایرادات ۱۰، بندهای ۶ و ۱۱): سطحِ canonical و قیمتِ هر مدت. `price` و `months` ِ تک‌ستونی پیشینه‌اند و
             * خرید دیگر از آن‌ها نمی‌خواند؛ فرمِ پیشین (تبِ باز) هنوز می‌فرستدشان.
             */
            'tier' => ['required', Rule::in(PlanTier::values())],
            'description' => ['nullable', 'string', 'max:300'],
            'price' => ['sometimes', 'integer', 'min:0'],
            'months' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'prices' => ['required', 'array', 'min:1', 'max:'.count(PlanTier::DURATIONS)],
            'prices.*.months' => ['required', 'integer', 'distinct', Rule::in(PlanTier::DURATIONS)],
            // سقف فقط برای ردِ عددِ بی‌معنا (ده میلیارد تومان)؛ قیمت را کارفرما تعیین می‌کند
            'prices.*.price' => ['required', 'integer', 'min:0', 'max:10000000000'],
            'prices.*.is_active' => ['boolean'],
            'unit_limit' => ['nullable', 'integer', 'min:1'],
            'real_gateway' => ['boolean'],
            'excel_export' => ['boolean'],
            'features' => ['array'],
            'features.*' => ['string', 'max:120'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * برای هر سطح و نوع فقط یک پلنِ فعال — وگرنه کارتِ دوم بی‌صدا پنهان می‌ماند (`Plan::catalogFor`). پلنِ «هر دو نوع» و
     * پلنِ مخصوصِ یک نوع با هم مجازند: دومی اولی را برای همان نوع جایگزین می‌کند (ترتیبِ راه‌اندازیِ تعرفه‌ی تجاری).
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->boolean('is_active', true)) {
                return;
            }

            $taken = Plan::where('is_active', true)->where('tier', $this->input('tier'))
                ->when($this->filled('complex_type'), fn ($q) => $q->where('complex_type', $this->input('complex_type')), fn ($q) => $q->whereNull('complex_type'))
                ->when($this->route('plan'), fn ($q, Plan $plan) => $q->whereKeyNot($plan->id))
                ->exists();

            if ($taken) {
                $validator->errors()->add('tier', 'برای این سطح و این نوعِ مجتمع از پیش پلنِ فعالی هست؛ همان را ویرایش کنید یا اول غیرفعالش کنید.');
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'شناسه فقط می‌تواند حروف کوچک انگلیسی، عدد و خط تیره باشد.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'نام', 'slug' => 'شناسه', 'price' => 'قیمت', 'months' => 'مدت',
            'complex_type' => 'نوع مجتمع', 'tier' => 'سطح', 'description' => 'توضیح',
            'prices' => 'قیمتِ مدت‌ها', 'prices.*.months' => 'مدت', 'prices.*.price' => 'قیمت',
        ];
    }
}
