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

    /** درایورِ ساخته‌شده را نگه می‌داریم تا خطای آخرین ارسال هم در دسترس بماند. */
    protected ?SmsGateway $driver = null;

    public function driver(): SmsGateway
    {
        if ($this->driver !== null) {
            return $this->driver;
        }

        $name = SystemSettings::get('sms_driver', 'log');
        $config = SystemSettings::getJson('sms_config', []);

        return $this->driver = match ($name) {
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

    /** جزئیاتِ خطای آخرین ارسال (اگر درایور آن را نگه دارد)، برای «ارسال آزمایشی». */
    public function lastError(): ?string
    {
        $driver = $this->driver();

        return method_exists($driver, 'lastError') ? $driver->lastError() : null;
    }

    /** ارسالِ کدِ یک‌بارمصرف (تنها پیامکِ سامانه) — با پترن اگر درایور پشتیبانی کند. */
    public function sendOtp(string $phone, string $code): bool
    {
        return $this->driver()->sendOtp($phone, $code);
    }

    public function isLogDriver(): bool
    {
        return SystemSettings::get('sms_driver', 'log') === 'log';
    }
}
