<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Kavenegar (کاوه‌نگار) — https://kavenegar.com
 * Config: ['apikey' => ..., 'sender' => '10004346']
 */
class KavenegarSms implements SmsGateway
{
    public function __construct(protected array $config) {}

    public function send(string $phone, string $message): bool
    {
        $apikey = $this->config['apikey'] ?? '';

        try {
            $response = Http::asForm()->timeout(15)->post(
                "https://api.kavenegar.com/v1/{$apikey}/sms/send.json",
                [
                    'receptor' => $phone,
                    'sender' => $this->config['sender'] ?? '',
                    'message' => $message,
                ]
            );

            $status = $response->json('return.status');
            if ($response->successful() && (int) $status === 200) {
                return true;
            }

            Log::warning('[SMS:kavenegar] failed', ['body' => $response->body()]);
        } catch (\Throwable $e) {
            Log::error('[SMS:kavenegar] exception: '.$e->getMessage());
        }

        return false;
    }
}
