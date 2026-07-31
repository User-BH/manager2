<?php

namespace App\Http\Requests;

/**
 * پیامِ تازه در پیام‌رسانِ داخلی.
 *
 * از `Api/MessengerController.php::store()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class StoreMessageRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:1000']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['body.required' => 'متن پیام را وارد کنید.', 'body.max' => 'پیام بیش از حد طولانی است.'];
    }
}
