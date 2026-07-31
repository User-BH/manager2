<?php

namespace App\Http\Requests;

/**
 * فعال‌سازیِ دستیِ یک پکیج برای مجتمع.
 *
 * از `Api/System/PlanController.php::grant()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class GrantPlanRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'complex_id' => ['required', 'exists:complexes,id'],
            'plan_id' => ['required', 'exists:plans,id'],
            'months' => ['nullable', 'integer', 'min:1', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['complex_id' => 'مجتمع', 'plan_id' => 'پکیج'];
    }
}
