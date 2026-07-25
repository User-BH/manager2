<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * IPPanel (آی‌پی‌پنل) — Edge API — https://edge.ippanel.com
 *
 * Config: [
 *   'apikey' => ...,               // مقدارِ هدرِ Authorization (خودِ توکن)
 *   'sender' => '+983000505',      // خطِ فرستنده (E.164)
 *   'pattern_code' => '...',       // کدِ پترنِ ثبت‌شده (برای OTP)
 *   'pattern_variable' => 'code',  // نامِ متغیرِ کد در پترن
 *   'phonebook_id' => 1234,        // اختیاری: شماره‌ی گیرنده در این دفترچه ذخیره می‌شود
 * ]
 *
 * ارسال با «پترن» انجام می‌شود (OTP سریع‌تر می‌رسد و با مقرراتِ سامانه‌های
 * پیامکیِ ایران سازگارتر است). اگر `pattern_code` تنظیم نشده باشد، به پیامکِ
 * متنیِ ساده (webservice) برمی‌گردد.
 */
class IppanelSms implements SmsGateway
{
    use SendsOtpAsMessage {
        sendOtp as sendOtpAsMessage;
    }

    private const ENDPOINT = 'https://edge.ippanel.com/v1/api/send';

    public function __construct(protected array $config) {}

    public function sendOtp(string $phone, string $code): bool
    {
        $pattern = trim((string) ($this->config['pattern_code'] ?? ''));

        // بدونِ پترن: همان پیامکِ متنیِ پیش‌فرض (شاملِ خطِ WebOTP) از راهِ send()
        if ($pattern === '') {
            return $this->sendOtpAsMessage($phone, $code);
        }

        $variable = trim((string) ($this->config['pattern_variable'] ?? '')) ?: 'code';

        $body = [
            'sending_type' => 'pattern',
            'from_number' => (string) ($this->config['sender'] ?? ''),
            'code' => $pattern,
            'recipients' => [$this->toE164($phone)],
            'params' => [$variable => $code],
        ];

        return $this->post($body, 'pattern');
    }

    public function send(string $phone, string $message): bool
    {
        $body = [
            'sending_type' => 'webservice',
            'from_number' => (string) ($this->config['sender'] ?? ''),
            'message' => $message,
            'recipients' => [$this->toE164($phone)],
        ];

        return $this->post($body, 'webservice');
    }

    /**
     * ارسال به Edge API و ذخیره‌ی همزمانِ شماره در دفترچه تلفن (اگر شناسه تنظیم شده).
     */
    private function post(array $body, string $kind): bool
    {
        // دفترچه تلفن (اختیاری): با تنظیم‌بودنِ شناسه، شماره‌ی گیرنده ذخیره می‌شود.
        if ($bookId = (int) ($this->config['phonebook_id'] ?? 0)) {
            $body['phonebook'] = ['id' => $bookId];
        }

        try {
            $response = Http::withHeaders([
                // Edge API خودِ توکن را در Authorization می‌خواهد (بدون پیشوند).
                'Authorization' => (string) ($this->config['apikey'] ?? ''),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(15)->post(self::ENDPOINT, $body);

            // بعضی خطاها با HTTP 200 ولی meta.status=false برمی‌گردند.
            if ($response->successful() && $response->json('meta.status') !== false) {
                return true;
            }

            Log::warning('[SMS:ippanel:'.$kind.'] failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[SMS:ippanel:'.$kind.'] exception: '.$e->getMessage());
        }

        return false;
    }

    /** شماره‌ی ایرانی را به E.164 (+98…) تبدیل می‌کند؛ Edge API همین را می‌خواهد. */
    private function toE164(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '0098')) {
            $digits = substr($digits, 4);
        } elseif (str_starts_with($digits, '98')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return '+98'.$digits;
    }
}
