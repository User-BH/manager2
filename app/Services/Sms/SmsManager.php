<?php

namespace App\Services\Sms;

use App\Support\Preview;
use App\Support\SmsOutcome;
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

        // Preview هرگز پیامکِ واقعی نمی‌فرستد، هرچه در تنظیماتش باشد (مرحلهٔ ۹ · فاز ۸)
        $name = Preview::active() ? 'log' : SystemSettings::get('sms_driver', 'log');
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
        return $this->record($this->driver()->send($phone, $message));
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
        return $this->record($this->driver()->sendOtp($phone, $code));
    }

    public function isLogDriver(): bool
    {
        return Preview::active() || SystemSettings::get('sms_driver', 'log') === 'log';
    }

    /**
     * شمارشِ نتیجه، برای هشدارِ «پیامک کار نمی‌کند» (R64).
     *
     * ⚠️ **اینجا** و نه در محلِ فراخوانی — دقیقاً همان استدلالِ قلاب‌های صف
     * در R63: چهار جای مختلف پیامک می‌فرستند و اولین جای پنجم که کسی
     * اضافه کند، از قلم می‌افتد. تستِ ساختاری هم می‌سنجد که هیچ‌کس درایور
     * را مستقیم نسازد و این لایه را دور نزند.
     *
     * 🔴 چرا این مهم‌ترین هشدارِ R64 است: پیامکِ این سامانه فقط کدِ ورود
     * است. قطعیِ کاملش یعنی هیچ‌کس نمی‌تواند وارد شود، در حالی که `/up`
     * سبز می‌ماند و هیچ استثنایی هیچ‌جا ثبت نمی‌شود.
     */
    private function record(bool $delivered): bool
    {
        SmsOutcome::record($delivered, $delivered ? null : $this->lastError());

        return $delivered;
    }
}
