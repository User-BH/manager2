<?php

namespace App\Services\Payment;

use App\Models\Payment;
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

    public function request(Payment $payment): array
    {
        try {
            $response = Http::timeout(30)->post(self::TOKEN_URL, [
                'action' => 'token',
                'TerminalId' => $this->config['terminal_id'] ?? '',
                'Amount' => $this->toRial($payment->amount),
                'ResNum' => (string) $payment->id,
                'RedirectUrl' => route('payments.callback', $payment),
                'CellNumber' => optional($payment->user)->phone,
            ]);

            $token = $response->json('token');
            if ($response->json('status') == 1 && $token) {
                $payment->update(['gateway' => 'saman', 'ref_id' => $token]);

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

    public function verify(Payment $payment, array $callback): ?string
    {
        // Bank posts back State/RefNum on success.
        if (($callback['State'] ?? '') !== 'OK') {
            return null;
        }

        $refNum = $callback['RefNum'] ?? null;

        try {
            $response = Http::timeout(30)->post(self::VERIFY_URL, [
                'RefNum' => $refNum,
                'TerminalNumber' => $this->config['terminal_id'] ?? '',
            ]);

            // ResultCode 0 (or 2 already-verified) means success.
            if (in_array((int) $response->json('ResultCode'), [0, 2], true)) {
                return (string) $refNum;
            }

            Log::warning('[Saman] verify failed', ['body' => $response->body()]);
        } catch (\Throwable $e) {
            Log::error('[Saman] verify exception: '.$e->getMessage());
        }

        return null;
    }

    private function toRial(float|string $amount): int
    {
        $amount = (int) round((float) $amount);

        return $this->currency === 'toman' ? $amount * 10 : $amount;
    }
}
