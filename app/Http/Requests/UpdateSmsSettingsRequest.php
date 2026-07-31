<?php

namespace App\Http\Requests;

use App\Services\Sms\SmsManager;

/**
 * تنظیماتِ سرویسِ پیامک.
 *
 * از `Api/System/SmsController.php::update()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class UpdateSmsSettingsRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sms_driver' => ['required', 'in:'.implode(',', array_keys(SmsManager::DRIVERS))],
            'apikey' => ['nullable', 'string', 'max:255'],
            'sender' => ['nullable', 'string', 'max:30'],
            'username' => ['nullable', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:100'],
            'pattern_code' => ['nullable', 'string', 'max:50'],
            'pattern_variable' => ['nullable', 'string', 'max:50'],
            'phonebook_id' => ['nullable', 'string', 'max:20'],
            'otp_disabled' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['sms_driver' => 'سامانه پیامک'];
    }
}
