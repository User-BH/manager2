<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * IPPanel (آی‌پی‌پنل / فراپیامک) — https://ippanel.com
 * Config: ['apikey' => ..., 'sender' => '+983000505']
 */
class IppanelSms implements SmsGateway
{
    public function __construct(protected array $config) {}

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
