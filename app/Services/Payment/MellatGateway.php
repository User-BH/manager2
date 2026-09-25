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

        /*
         * 🔴 فروش باید مالِ **همین** سفارش باشد (مرحلهٔ ۵ · فاز ۹).
         *
         * پیش از این `SaleOrderId` از خودِ بازگشت خوانده می‌شد — یعنی از
         * مرورگرِ کاربر. کسی که قبضِ ارزانی را پرداخت می‌کرد و بازگشتش را
         * رها می‌کرد، می‌توانست `SaleOrderId`/`SaleReferenceId`ِ همان فروش را
         * به بازگشتِ قبضِ گران بفرستد؛ `bpVerifyRequest` آن فروش را تأیید
         * می‌کرد و قبضِ گران با پولِ ارزان تسویه می‌شد.
         *
         * ملت در تأیید مبلغ برنمی‌گرداند؛ ولی شماره‌ی سفارش را **خودمان** در
         * `bpPayRequest` با مبلغِ خودمان فرستاده‌ایم. پس گره‌زدنِ فروش به
         * شماره‌ی سفارشِ خودمان، مبلغ را هم گره می‌زند. ناهمخوانی پیش از
         * تأیید رد می‌شود، پس فروشِ بیگانه اصلاً تأیید نمی‌شود.
         */
        $saleOrderId = (string) $order->gatewayOrderId();

        if (isset($callback['SaleOrderId']) && (string) $callback['SaleOrderId'] !== $saleOrderId) {
            Log::warning('[Mellat] callback carries another order', [
                'order' => $saleOrderId,
                'sale_order_id' => (string) $callback['SaleOrderId'],
            ]);

            return null;
        }

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
            // بانک صریح «نه» گفت. اگر نرسیده بودیم، `call` استثنا داده بود.
            return null;
        }

        /*
         * 🔴 نتیجه‌ی تسویه دیگر نادیده گرفته نمی‌شود (R59).
         *
         * ─── چه چیزی بی‌صدا می‌شکست ────────────────────────────────────────
         * پیش از این این خط بود و بس:
         *
         *     $this->call($this->soapBody('bpSettleRequest', $args), ...);
         *
         * خروجی دور ریخته می‌شد. یعنی اگر `bpVerifyRequest` موفق بود ولی
         * `bpSettleRequest` به timeout می‌خورد، این متد **کدِ رهگیری
         * برمی‌گرداند** و سامانه پرداخت را «موفق» ثبت می‌کرد: قبض تسویه،
         * کیفِ پول شارژ، و پولی که هرگز capture نشده بود.
         *
         * در پروتکلِ ملت، تراکنشی که verify شده ولی settle نشده پس از
         * پنجره‌ی تسویه **برگشت می‌خورد**. یعنی مجتمع پولی را که در دفترش
         * ثبت کرده بود، هرگز دریافت نمی‌کرد.
         *
         * ─── چرا «هر کدِ ناشناخته = نامشخص» و نه «ناموفق» ──────────────────
         * ⚠️ جدولِ کدهای ملت را نمی‌توانم روی ترمینالِ واقعی بسنجم. پس
         * به‌جای حدس‌زدن، فقط دو کدی که مطمئنم موفقیت‌اند پذیرفته می‌شوند و
         * **هر چیز دیگری به «نامشخص» می‌رود**، نه به «ناموفق».
         *
         * جهتِ خطا عمدی است: بدترین نتیجه‌ی این انتخاب، ردیفی است که
         * اپراتور باید دستی بررسی کند. بدترین نتیجه‌ی انتخابِ دیگر، پولِ
         * از دست رفته است.
         */
        $settle = (string) $this->call($this->soapBody('bpSettleRequest', $args), 'bpSettleRequest');

        // ۰ = تسویه شد · ۴۵ = از قبل تسویه شده بود (مستندِ ملت؛ روی ترمینالِ واقعی سنجیده نشده)
        if (! in_array($settle, ['0', '45'], true)) {
            throw GatewayUnreachable::settleUnconfirmed('mellat', 'کدِ تسویه: '.($settle === '' ? 'خالی' : $settle));
        }

        return (string) $saleReferenceId;
    }

    /**
     * یک فراخوانیِ SOAP.
     *
     * ⚠️ **نرسیدن به بانک، «نه» گفتنِ بانک نیست** (R59).
     *
     * نسخه‌ی قبلی هر استثنا را می‌بلعید و `''` برمی‌گرداند — و `''` در
     * مقایسه با `'0'` نابرابر است، پس timeoutِ شبکه دقیقاً مثلِ ردِ بانک
     * دیده می‌شد. نتیجه‌اش این بود که یک قطعیِ گذرای شبکه، پرداختی را
     * **برای همیشه** ناموفق علامت می‌زد، در حالی که ممکن بود پولِ کاربر
     * واقعاً کسر شده باشد.
     *
     * حالا این دو حالت از هم جدا شده‌اند: پاسخِ نامفهومِ بانک `''` است
     * (یعنی «نه»)، ولی نرسیدن به بانک استثنا می‌دهد.
     */
    private function call(string $body, string $action): string
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => $action,
            ])->connectTimeout(5)->timeout(30)->withBody($body, 'text/xml')->post(self::WSDL);
        } catch (\Throwable $e) {
            Log::error('[Mellat] '.$action.' exception: '.$e->getMessage());

            throw GatewayUnreachable::transport('mellat', $action, $e->getMessage());
        }

        if ($response->serverError()) {
            // ۵xx یعنی «الان نمی‌توانم»، نه «این تراکنش باطل است»
            Log::error('[Mellat] '.$action.' server error: '.$response->status());

            throw GatewayUnreachable::transport('mellat', $action, 'HTTP '.$response->status());
        }

        if (preg_match('/<return[^>]*>(.*?)<\/return>/s', $response->body(), $m)) {
            return trim($m[1]);
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
