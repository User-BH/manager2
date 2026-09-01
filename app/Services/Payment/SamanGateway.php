<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * سامان / سپ (Saman / SEP).
 * Token-based flow: request a token, redirect the payer to the gateway with it,
 * then verify the transaction on callback.
 *
 * Config (per complex gateway_config): terminal_id. Amounts are in Rial.
 */
class SamanGateway implements PaymentGateway
{
    private const TOKEN_URL = 'https://sep.shaparak.ir/onlinepg/onlinepg';

    private const PAY_URL = 'https://sep.shaparak.ir/OnlinePG/SendToken';

    private const VERIFY_URL = 'https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg/VerifyTransaction';

    public function __construct(protected array $config, protected string $currency = 'toman') {}

    public function request(GatewayOrder $order): array
    {
        try {
            $response = Http::connectTimeout(5)->timeout(30)->post(self::TOKEN_URL, [
                'action' => 'token',
                'TerminalId' => $this->config['terminal_id'] ?? '',
                'Amount' => $this->toRial($order->gatewayAmount()),
                'ResNum' => (string) $order->gatewayOrderId(),
                'RedirectUrl' => $order->gatewayCallbackUrl(),
                'CellNumber' => $order->gatewayPayerPhone(),
            ]);

            $token = $response->json('token');
            if ($response->json('status') == 1 && $token) {
                $order->markGatewayRequested('saman', $token);

                return [
                    'method' => 'POST',
                    'redirect_url' => self::PAY_URL,
                    'ref_id' => $token,
                    'fields' => ['Token' => $token],
                ];
            }

            Log::warning('[Saman] token request failed', ['body' => $response->body()]);
        } catch (\Throwable $e) {
            Log::error('[Saman] token exception: '.$e->getMessage());
        }

        throw new \RuntimeException('اتصال به درگاه سامان ناموفق بود.');
    }

    public function verify(GatewayOrder $order, array $callback): ?string
    {
        // Bank posts back State/RefNum on success.
        if (($callback['State'] ?? '') !== 'OK') {
            return null;
        }

        $refNum = $callback['RefNum'] ?? null;

        try {
            $response = Http::connectTimeout(5)->timeout(30)->post(self::VERIFY_URL, [
                'RefNum' => $refNum,
                'TerminalNumber' => $this->config['terminal_id'] ?? '',
            ]);
        } catch (\Throwable $e) {
            /*
             * ⚠️ **نرسیدن به بانک، «نه» گفتنِ بانک نیست** (R59).
             *
             * نسخه‌ی قبلی اینجا `null` برمی‌گرداند و کنترلر پرداخت را برای
             * همیشه `Failed` می‌کرد — برای تراکنشی که وضعیتش را اصلاً
             * نپرسیده بودیم. کاربر پیامِ «اگر مبلغی کسر شده برمی‌گردد»
             * می‌گرفت، که تضمینی بود درباره‌ی چیزی که نمی‌دانستیم.
             */
            Log::error('[Saman] verify exception: '.$e->getMessage());

            throw GatewayUnreachable::transport('saman', 'verify', $e->getMessage());
        }

        if ($response->serverError()) {
            Log::error('[Saman] verify server error: '.$response->status());

            throw GatewayUnreachable::transport('saman', 'verify', 'HTTP '.$response->status());
        }

        // ResultCode 0 (or 2 already-verified) means success.
        if (in_array((int) $response->json('ResultCode'), [0, 2], true)) {
            return (string) $refNum;
        }

        // اینجا بانک واقعاً پاسخ داده و پاسخش «نه» است
        Log::warning('[Saman] verify failed', ['body' => $response->body()]);

        return null;
    }

    private function toRial(float|string $amount): int
    {
        $amount = (int) round((float) $amount);

        return $this->currency === 'toman' ? $amount * 10 : $amount;
    }
}
