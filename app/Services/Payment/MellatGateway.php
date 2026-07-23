<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * به‌پرداخت ملت (BehPardakht / Mellat).
 * Two-step SOAP flow (bpPayRequest -> startpay -> bpVerifyRequest + bpSettleRequest).
 * Implemented as raw SOAP-over-HTTP so it works without the PHP soap extension.
 *
 * Config (per complex gateway_config): terminal_id, username, password.
 * Bank amounts are in Rial; toman amounts are multiplied by 10.
 */
class MellatGateway implements PaymentGateway
{
    private const WSDL = 'https://bpm.shaparak.ir/pgwchannel/services/pgw';

    private const STARTPAY = 'https://bpm.shaparak.ir/pgwchannel/startpay.mellat';

    public function __construct(protected array $config, protected string $currency = 'toman') {}

    public function request(GatewayOrder $order): array
    {
        $amount = $this->toRial($order->gatewayAmount());
        $orderId = $order->gatewayOrderId();

        $body = $this->soapBody('bpPayRequest', [
            'terminalId' => $this->config['terminal_id'] ?? '',
            'userName' => $this->config['username'] ?? '',
            'userPassword' => $this->config['password'] ?? '',
            'orderId' => $orderId,
            'amount' => $amount,
            'localDate' => now()->format('Ymd'),
            'localTime' => now()->format('His'),
            'additionalData' => $order->gatewayDescription(),
            'callBackUrl' => $order->gatewayCallbackUrl(),
            'payerId' => 0,
        ]);

        $result = $this->call($body, 'bpPayRequest');
        // Response format: "ResCode,RefId"
        [$resCode, $refId] = array_pad(explode(',', (string) $result), 2, null);

        if ((string) $resCode !== '0' || ! $refId) {
            Log::warning('[Mellat] pay request failed', ['res' => $result]);
            throw new \RuntimeException('اتصال به درگاه ملت ناموفق بود (کد '.$resCode.').');
        }

        $order->markGatewayRequested('mellat', $refId);

        // Mellat requires an auto-submitted POST to the start-pay page.
        return [
            'method' => 'POST',
            'redirect_url' => self::STARTPAY,
            'ref_id' => $refId,
            'fields' => ['RefId' => $refId],
        ];
    }

    public function verify(GatewayOrder $order, array $callback): ?string
    {
        if ((string) ($callback['ResCode'] ?? '') !== '0') {
            return null;
        }

        $saleOrderId = $callback['SaleOrderId'] ?? $order->gatewayOrderId();
        $saleReferenceId = $callback['SaleReferenceId'] ?? '';

        $args = [
            'terminalId' => $this->config['terminal_id'] ?? '',
            'userName' => $this->config['username'] ?? '',
            'userPassword' => $this->config['password'] ?? '',
            'orderId' => $order->gatewayOrderId(),
            'saleOrderId' => $saleOrderId,
            'saleReferenceId' => $saleReferenceId,
        ];

        if ((string) $this->call($this->soapBody('bpVerifyRequest', $args), 'bpVerifyRequest') !== '0') {
            return null;
        }

        // Settle so the funds are actually captured.
        $this->call($this->soapBody('bpSettleRequest', $args), 'bpSettleRequest');

        return (string) $saleReferenceId;
    }

    private function call(string $body, string $action): string
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => $action,
            ])->timeout(30)->withBody($body, 'text/xml')->post(self::WSDL);

            if (preg_match('/<return[^>]*>(.*?)<\/return>/s', $response->body(), $m)) {
                return trim($m[1]);
            }
        } catch (\Throwable $e) {
            Log::error('[Mellat] '.$action.' exception: '.$e->getMessage());
        }

        return '';
    }

    private function soapBody(string $method, array $args): string
    {
        $params = '';
        foreach ($args as $k => $v) {
            $params .= "<{$k}>".htmlspecialchars((string) $v)."</{$k}>";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:int="http://interfaces.core.sw.bps.com/">'
            ."<soapenv:Body><int:{$method}>{$params}</int:{$method}></soapenv:Body></soapenv:Envelope>";
    }

    private function toRial(float|string $amount): int
    {
        $amount = (int) round((float) $amount);

        return $this->currency === 'toman' ? $amount * 10 : $amount;
    }
}
