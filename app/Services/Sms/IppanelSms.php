<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * IPPanel (آی‌پی‌پنل / فراپیامک) — https://ippanel.com
 *
 * Config: [
 *   'apikey' => ...,               // AccessKey
 *   'sender' => '+983000505',      // خط فرستنده
 *   'pattern_code' => '...',       // کدِ پترنِ ثبت‌شده در پنل (برای OTP)
 *   'pattern_variable' => 'code',  // نامِ متغیرِ کد داخلِ پترن
 * ]
 *
 * ارسالِ OTP با «پترن» انجام می‌شود: سریع‌تر می‌رسد و با مقرراتِ سامانه‌های
 * پیامکیِ ایران سازگارتر است. اگر pattern_code تنظیم نشده باشد، به پیامکِ
 * متنیِ ساده برمی‌گردد.
 */
class IppanelSms implements SmsGateway
{
    use SendsOtpAsMessage {
        sendOtp as sendOtpAsMessage;
    }

    public function __construct(protected array $config) {}

    public function sendOtp(string $phone, string $code): bool
    {
        $pattern = trim((string) ($this->config['pattern_code'] ?? ''));

        // بدونِ پترن: همان پیامکِ متنیِ پیش‌فرض (شاملِ خطِ WebOTP)
        if ($pattern === '') {
            return $this->sendOtpAsMessage($phone, $code);
        }

        $variable = $this->config['pattern_variable'] ?? 'code';

        try {
            $response = Http::withHeaders([
                'Authorization' => 'AccessKey '.($this->config['apikey'] ?? ''),
                'Content-Type' => 'application/json',
            ])->timeout(15)->post(
                'https://api2.ippanel.com/api/v1/sms/pattern/normal/send',
                [
                    'code' => $pattern,
                    'sender' => $this->config['sender'] ?? '',
                    'recipient' => $phone,
                    'variable' => [$variable => $code],
                ]
            );

            if ($response->successful()) {
                return true;
            }

            Log::warning('[SMS:ippanel:pattern] failed', ['body' => $response->body()]);
        } catch (\Throwable $e) {
            Log::error('[SMS:ippanel:pattern] exception: '.$e->getMessage());
        }

        return false;
    }

    public function send(string $phone, string $message): bool
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'AccessKey '.($this->config['apikey'] ?? ''),
                'Content-Type' => 'application/json',
            ])->timeout(15)->post(
                'https://api2.ippanel.com/api/v1/sms/send/webservice/single',
                [
                    'recipient' => [$phone],
                    'sender' => $this->config['sender'] ?? '',
                    'message' => $message,
                ]
            );

            if ($response->successful()) {
                return true;
            }

            Log::warning('[SMS:ippanel] failed', ['body' => $response->body()]);
        } catch (\Throwable $e) {
            Log::error('[SMS:ippanel] exception: '.$e->getMessage());
        }

        return false;
    }
}
