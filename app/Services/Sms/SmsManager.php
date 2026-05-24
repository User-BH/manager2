<?php

namespace App\Services\Sms;

use App\Support\SystemSettings;

/**
 * Resolves the active SMS driver from system settings (configured by the
 * super-admin). Falls back to the log driver so OTP login works out of the
 * box without real credentials during development/testing.
 */
class SmsManager
{
    public const DRIVERS = [
        'log' => 'حالت تست (ثبت در لاگ)',
        'kavenegar' => 'کاوه‌نگار',
        'ippanel' => 'آی‌پی‌پنل (IPPanel)',
        'melipayamak' => 'ملی پیامک',
    ];

    public function driver(): SmsGateway
    {
        $name = SystemSettings::get('sms_driver', 'log');
        $config = SystemSettings::getJson('sms_config', []);

        return match ($name) {
            'kavenegar' => new KavenegarSms($config),
            'ippanel' => new IppanelSms($config),
            'melipayamak' => new MelipayamakSms($config),
            default => new LogSms,
        };
    }

    public function send(string $phone, string $message): bool
    {
        return $this->driver()->send($phone, $message);
    }

    public function isLogDriver(): bool
    {
        return SystemSettings::get('sms_driver', 'log') === 'log';
    }
}
