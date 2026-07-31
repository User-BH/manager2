<?php

namespace App\Http\Requests;

/**
 * ثبتِ درآمدِ مجتمع.
 *
 * از `Api/FinanceController.php::storeIncome()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class StoreIncomeRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'min:0'],
            'source' => ['nullable', 'string', 'max:120'],
            'period' => ['required', 'string', 'max:7'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['title' => 'عنوان', 'amount' => 'مبلغ', 'period' => 'دوره'];
    }
}
