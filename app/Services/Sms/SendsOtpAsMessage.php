<?php

namespace App\Services\Sms;

/**
 * پیش‌فرضِ ارسالِ کدِ یک‌بارمصرف به‌صورت پیامکِ متنی.
 *
 * درایوری که پترن ندارد (log، کاوه‌نگار، ملی‌پیامک) کد را داخلِ یک متنِ آماده
 * می‌فرستد. آخرین خط عمداً با فرمتِ WebOTP است (`@دامنه #کد`) تا در کروم
 * اندروید کد به‌صورت خودکار در فیلد پر شود.
 */
trait SendsOtpAsMessage
{
    public function sendOtp(string $phone, string $code): bool
    {
        return $this->send($phone, static::otpMessage($code));
    }

    public static function otpMessage(string $code): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'sakena.app';
        $brand = config('brand.name', 'ساکنا');

        return "کد ورود شما به سامانه {$brand}: {$code}\n"
            ."این کد را در اختیار کسی قرار ندهید.\n\n"
            ."@{$host} #{$code}";
    }
}
