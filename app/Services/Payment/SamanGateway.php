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

        /*
         * 🔴 تراکنشِ بانک باید مالِ **همین** سفارش باشد (مرحلهٔ ۵ · فاز ۹).
         *
         * همه‌ی مقادیرِ بازگشتی از مرورگرِ کاربر می‌آیند. پیش از این `RefNum`
         * هر چه بود تأیید می‌شد: کسی که قبضِ ارزانی را پرداخت می‌کرد و
         * بازگشتش را رها می‌کرد، می‌توانست همان `RefNum` را به بازگشتِ قبضِ
         * گران بفرستد و بانک «تأیید شد» می‌گفت — چون آن تراکنش واقعاً روی
         * همین ترمینال انجام شده بود.
         *
         * ① `ResNum`ِ بازگشتی، اگر هست، باید شماره‌ی سفارشِ خودمان باشد —
         *   پیش از تأیید، تا تراکنشِ بیگانه اصلاً تأیید نشود.
         * ② مبلغِ تأییدشده (پایین) باید مبلغِ همین سفارش باشد.
         */
        if (isset($callback['ResNum']) && (string) $callback['ResNum'] !== (string) $order->gatewayOrderId()) {
            Log::warning('[Saman] callback carries another order', [
                'order' => $order->gatewayOrderId(),
                'res_num' => (string) $callback['ResNum'],
            ]);

            return null;
        }

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
            /*
             * ② مبلغِ تراکنشی که بانک تأیید کرد = مبلغِ همین سفارش.
             *
             * ⚠️ نبودِ مبلغ در پاسخ «ناموفق» نیست، «نامعلوم» است: بانک
             * تأیید کرده و پول شاید کسر شده. ناموفق‌خواندنش همان خطای R59
             * بود — پولی که از دفترها ناپدید می‌شد. نامعلوم به بررسیِ دستی
             * می‌رود (`payments:stale`).
             *
             * 🔴 ناهمخوانی هم به همان دلیل نامعلوم است نه ناموفق: تراکنشِ
             * دیگری روی ترمینالِ ما همین حالا تأیید شد و پولش باید پیدا شود.
             */
            $amount = $response->json('TransactionDetail.OrginalAmount')
                ?? $response->json('TransactionDetail.AffectiveAmount');

            if ($amount === null) {
                throw GatewayUnreachable::unmatched('saman', 'مبلغ در پاسخِ تأییدِ بانک نیامد');
            }

            if ((int) $amount !== $this->toRial($order->gatewayAmount())) {
                Log::warning('[Saman] verified amount does not match the order', [
                    'order' => $order->gatewayOrderId(),
                    'verified' => (int) $amount,
                    'expected' => $this->toRial($order->gatewayAmount()),
                ]);

                throw GatewayUnreachable::unmatched('saman', sprintf(
                    'مبلغِ تأییدشده %d ریال، مبلغِ سفارش %d ریال',
                    (int) $amount,
                    $this->toRial($order->gatewayAmount()),
                ));
            }

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
