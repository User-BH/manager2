<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Development driver: writes the SMS to the application log instead of
 * sending it. Lets the OTP login flow be tested without real credentials.
 */
class LogSms implements SmsGateway
{
    use SendsOtpAsMessage;

    public function send(string $phone, string $message): bool
    {
        /*
         * متنِ پیام فقط در محیطِ توسعه لاگ می‌شود (R20).
         *
         * تنها پیامکِ این سامانه کدِ ورود است. اگر این درایور در محصول فعال
         * بماند — که پیش‌فرض هم هست — هر کدِ ورود به‌صورتِ متنِ ساده کنارِ
         * شماره‌ی صاحبش در `laravel.log` می‌نشیند، و هرکس به فایلِ لاگ دسترسی
         * داشته باشد می‌تواند به‌جای هر کاربری وارد شود.
         */
        if (app()->environment('local', 'testing')) {
            Log::info("[SMS:log] to={$phone} message={$message}");
        } else {
            Log::warning(
                '[SMS:log] پیامک ارسال نشد — درایورِ آزمایشی در محیطِ غیرتوسعه فعال است. '
                .'سامانه‌ی پیامک را در پنل ادمین تنظیم کنید.',
                ['to' => $phone],
            );
        }

        return true;
    }
}
