<?php

namespace App\Http\Requests;

use App\Enums\ChargeRuleType;

/**
 * قاعده‌ی محاسبه‌ی شارژ.
 *
 * از `Api/ChargeRuleController.php::store()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class StoreChargeRuleRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:'.implode(',', array_column(ChargeRuleType::cases(), 'value'))],
            'category' => ['required', 'in:owner,tenant'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'base' => ['nullable', 'numeric', 'min:0'],
            'per_area_rate' => ['nullable', 'numeric', 'min:0'],
            'per_person_rate' => ['nullable', 'numeric', 'min:0'],
            'pool_amount' => ['nullable', 'numeric', 'min:0'],
            'exempt_ground_floor' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'نام قانون', 'type' => 'نوع', 'category' => 'دسته'];
    }
}
