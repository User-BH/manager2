<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * تخفیفِ یک واحد در یک دوره.
 *
 * از `Api/DiscountController.php::store()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class StoreDiscountRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // exists خام به مجتمع محدود نیست؛ بدون این قید می‌شد با دستکاری
            // شناسه، تخفیف را به واحدِ مجتمع دیگری بست.
            'unit_id' => [
                'required',
                Rule::exists('units', 'id')->where('complex_id', $this->currentComplexId()),
            ],
            // الگوی دوره‌ی شمسی؛ پیش از این هر رشته‌ای پذیرفته می‌شد و تخفیفی
            // ثبت می‌شد که با هیچ دوره‌ای مطابقت نمی‌کرد.
            'period' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'amount' => ['required', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'period.regex' => 'دوره باید به شکل ۱۴۰۴-۰۳ باشد.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['unit_id' => 'واحد', 'amount' => 'مبلغ تخفیف', 'period' => 'دوره'];
    }
}
