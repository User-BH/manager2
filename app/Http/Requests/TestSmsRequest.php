<?php

namespace App\Http\Requests;

/**
 * ارسالِ پیامکِ آزمایشی.
 *
 * از `Api/System/SmsController.php::test()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class TestSmsRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['phone' => ['required', 'string']];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['phone' => 'شماره تلفن'];
    }
}
