<?php

namespace App\Http\Requests;

/**
 * ردِ رسیدِ اشتراک با دلیل.
 *
 * از `Api/System/SubscriptionReviewController.php::reject()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class RejectSubscriptionRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:300'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['note' => 'دلیل رد'];
    }
}
