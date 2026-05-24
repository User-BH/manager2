<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Development driver: writes the SMS to the application log instead of
 * sending it. Lets the OTP login flow be tested without real credentials.
 */
class LogSms implements SmsGateway
{
    public function send(string $phone, string $message): bool
    {
        Log::info("[SMS:log] to={$phone} message={$message}");

        return true;
    }
}
