<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

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
            'price' => ['required', 'integer', 'min:0'],
            'months' => ['required', 'integer', 'min:1', 'max:60'],
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
        ];
    }
}
