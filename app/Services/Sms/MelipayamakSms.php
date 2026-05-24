<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Melipayamak (ملی پیامک) — https://melipayamak.com
 * Config: ['username' => ..., 'password' => ..., 'sender' => '50004001...']
 */
class MelipayamakSms implements SmsGateway
{
    public function __construct(protected array $config) {}

    public function send(string $phone, string $message): bool
    {
        try {
            $response = Http::asForm()->timeout(15)->post(
                'https://rest.payamak-panel.com/api/SendSMS/SendSMS',
                [
                    'username' => $this->config['username'] ?? '',
                    'password' => $this->config['password'] ?? '',
                    'to' => $phone,
                    'from' => $this->config['sender'] ?? '',
                    'text' => $message,
                ]
            );

            // RetStatus = 1 means accepted.
            if ($response->successful() && (int) $response->json('RetStatus') === 1) {
                return true;
            }

            Log::warning('[SMS:melipayamak] failed', ['body' => $response->body()]);
        } catch (\Throwable $e) {
            Log::error('[SMS:melipayamak] exception: '.$e->getMessage());
        }

        return false;
    }
}
