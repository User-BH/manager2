<?php

namespace App\Http\Requests;

/**
 * لغوِ دستیِ پکیجِ یک مجتمع.
 *
 * از `Api/System/PlanController.php::revoke()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class RevokePlanRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'complex_id' => ['required', 'exists:complexes,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['complex_id' => 'مجتمع'];
    }
}
