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

    /** آخرین خطای ارسال، برای نمایش در «ارسال آزمایشی». */
    protected ?string $lastError = null;

    public function __construct(protected array $config) {}

    public function lastError(): ?string
    {
        return $this->lastError;
    }

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
        $this->lastError = null;

        // دفترچه تلفن (اختیاری): با تنظیم‌بودنِ شناسه، شماره‌ی گیرنده ذخیره می‌شود.
        if ($bookId = (int) ($this->config['phonebook_id'] ?? 0)) {
            $body['phonebook'] = ['id' => $bookId];
        }

        // آدرسِ apikey جز چند رقم آخر لاگ نمی‌شود.
        $keyHint = $this->maskKey((string) ($this->config['apikey'] ?? ''));

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

            $metaMessage = $response->json('meta.message');
            $this->lastError = trim(sprintf(
                'HTTP %d%s | %s',
                $response->status(),
                $metaMessage ? ' — '.$metaMessage : '',
                $response->body(),
            ));

            // لاگِ کامل تا بشود دقیقاً دلیلِ ناموفق‌بودن را دید.
            Log::warning('[SMS:ippanel:'.$kind.'] failed', [
                'endpoint' => self::ENDPOINT,
                'status' => $response->status(),
                'sending_type' => $body['sending_type'] ?? null,
                'from_number' => $body['from_number'] ?? null,
                'recipients' => $body['recipients'] ?? null,
                'has_pattern' => isset($body['code']),
                'apikey' => $keyHint,
                'response' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            $this->lastError = 'استثنا در ارتباط با ایپ‌پنل: '.$e->getMessage();

            Log::error('[SMS:ippanel:'.$kind.'] exception', [
                'endpoint' => self::ENDPOINT,
                'message' => $e->getMessage(),
                'apikey' => $keyHint,
            ]);
        }

        return false;
    }

    /** فقط چهار رقم آخرِ کلید در لاگ می‌ماند، تا کلید کامل لو نرود. */
    private function maskKey(string $key): string
    {
        $len = strlen($key);

        return $len <= 4 ? str_repeat('*', $len) : str_repeat('*', $len - 4).substr($key, -4);
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
