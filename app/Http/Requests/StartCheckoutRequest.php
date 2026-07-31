<?php

namespace App\Http\Requests;

/**
 * شروعِ پرداختِ اشتراک از درگاه.
 *
 * از `SubscriptionCheckoutController.php::start()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class StartCheckoutRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plan' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['plan' => 'پلن'];
    }
}
