<?php

namespace App\Http\Requests;

/**
 * ردِ رسید با یادداشتِ دلیل.
 *
 * از `Api/PaymentReviewController.php::reject()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class RejectPaymentRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['note' => 'توضیح'];
    }
}
