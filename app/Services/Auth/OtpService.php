<?php

namespace App\Services\Auth;

use App\Models\OtpCode;
use App\Services\Sms\SmsManager;
use App\Support\Phone;
use Illuminate\Support\Facades\Hash;

class OtpService
{
    public const TTL_SECONDS = 120;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_COOLDOWN = 60;

    public function __construct(protected SmsManager $sms) {}

    /**
     * Generate and send a one-time code. Returns the plain code only when the
     * log (test) driver is active, so the tester can read it on screen.
     *
     * @return array{ok:bool, dev_code:?string, error:?string}
     */
    public function request(string $phone): array
    {
        $phone = Phone::normalize($phone);

        // Cooldown: block rapid re-requests.
        $recent = OtpCode::where('phone', $phone)
            ->where('created_at', '>', now()->subSeconds(self::RESEND_COOLDOWN))
            ->exists();
        if ($recent) {
            return ['ok' => false, 'dev_code' => null, 'error' => 'برای ارسال مجدد کد کمی صبر کنید.'];
        }

        $code = (string) random_int(100000, 999999);

        OtpCode::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
        ]);

        $message = 'کد ورود شما به سامانه مدیریت ساختمان: '.$code;
        $sent = $this->sms->send($phone, $message);

        return [
            'ok' => $sent,
            'dev_code' => $this->sms->isLogDriver() ? $code : null,
            'error' => $sent ? null : 'ارسال پیامک ناموفق بود. بعدا تلاش کنید.',
        ];
    }

    public function verify(string $phone, string $code): bool
    {
        $phone = Phone::normalize($phone);

        $otp = OtpCode::where('phone', $phone)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (! $otp || $otp->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        $otp->increment('attempts');

        if (! Hash::check($code, $otp->code_hash)) {
            return false;
        }

        $otp->update(['used_at' => now()]);

        return true;
    }
}
