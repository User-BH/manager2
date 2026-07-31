<?php

namespace App\Http\Requests;

use App\Enums\ChargeRuleType;

/**
 * ثبتِ هزینه‌ی مجتمع.
 *
 * از `Api/FinanceController.php::storeExpense()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class StoreExpenseRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'min:0'],
            'category' => ['required', 'in:owner,tenant'],
            'period' => ['required', 'string', 'max:7'],
            'split_method' => ['nullable', 'in:'.implode(',', array_column(ChargeRuleType::cases(), 'value'))],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['title' => 'عنوان', 'amount' => 'مبلغ', 'category' => 'دسته', 'period' => 'دوره'];
    }
}
