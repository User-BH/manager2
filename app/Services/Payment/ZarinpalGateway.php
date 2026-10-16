<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * زرین‌پال — نسخه‌ی ۴ ِ REST (مرحلهٔ ۹ · فاز ۴؛ انتخابِ کارفرما برای درگاهِ سکو).
 *
 * درخواست ← `authority` ← هدایت به StartPay ← بازگشت با `Authority` و `Status`
 * ← تأیید با همان مبلغ. با کلاینتِ HTTP ِ خودِ لاراول، بی پکیجِ تازه.
 *
 * ⚠️ نشانی‌ها از مستنداتِ عمومیِ زرین‌پال‌اند و با `merchant_id` ِ واقعی هنوز
 * سنجیده نشده‌اند: IMPLEMENTATION READY — ENVIRONMENT VERIFICATION REQUIRED.
 * رفتارِ درایور با پاسخ‌های ساختگیِ HTTP کامل تست شده است (`ZarinpalGatewayTest`).
 *
 * همان قاعده‌های درایورهای بانکی (مرحلهٔ ۵ · فاز ۹ و R59):
 *  • `Authority` ِ بازگشتی باید مالِ **همین** سفارش باشد، پیش از تأیید.
 *  • نرسیدن به درگاه «نه» نیست، «نامعلوم» است (`GatewayUnreachable`).
 *  • ناهمخوانیِ مبلغ هم نامعلوم است: پولی شاید کسر شده و باید پیدا شود.
 */
class ZarinpalGateway implements PaymentGateway, Reverifiable
{
    /*
     * ⚠️ نشانی‌ها کامل و ثابت‌اند، نه «پایه + مسیر»: مقصدِ هر تماس باید ثابتِ کلاس
     * باشد تا از متغیر SSRF ساخته نشود (`OutboundSurfaceTest`).
     */
    private const LIVE_REQUEST = 'https://payment.zarinpal.com/pg/v4/payment/request.json';

    private const LIVE_VERIFY = 'https://payment.zarinpal.com/pg/v4/payment/verify.json';

    private const LIVE_START = 'https://payment.zarinpal.com/pg/StartPay/';

    private const SANDBOX_REQUEST = 'https://sandbox.zarinpal.com/pg/v4/payment/request.json';

    private const SANDBOX_VERIFY = 'https://sandbox.zarinpal.com/pg/v4/payment/verify.json';

    private const SANDBOX_START = 'https://sandbox.zarinpal.com/pg/StartPay/';

    /** پرداختِ موفق؛ ۱۰۱ یعنی «پیش‌تر تأیید شده» — بازگشتِ دوباره، نه پرداختِ تازه */
    private const OK = [100, 101];

    /** «مبلغِ ارسالی با مبلغِ پرداخت‌شده یکی نیست» */
    private const AMOUNT_MISMATCH = -50;

    /**
     * @param  array<string, mixed>  $config  `merchant_id`، `sandbox`
     */
    public function __construct(protected array $config, protected string $currency = 'toman') {}

    public function request(GatewayOrder $order): array
    {
        $payload = array_filter([
            'merchant_id' => (string) ($this->config['merchant_id'] ?? ''),
            'amount' => $this->toRial($order->gatewayAmount()),
            'currency' => 'IRR',
            'callback_url' => $order->gatewayCallbackUrl(),
            'description' => $order->gatewayDescription(),
            'metadata' => array_filter(['mobile' => $order->gatewayPayerPhone()]) ?: null,
        ], fn ($value) => $value !== null);

        try {
            $response = $this->sandbox()
                ? Http::acceptJson()->asJson()->connectTimeout(5)->timeout(30)->post(self::SANDBOX_REQUEST, $payload)
                : Http::acceptJson()->asJson()->connectTimeout(5)->timeout(30)->post(self::LIVE_REQUEST, $payload);

            $authority = $response->json('data.authority');
            if ((int) $response->json('data.code') === 100 && is_string($authority) && $authority !== '') {
                $order->markGatewayRequested('zarinpal', $authority);

                return [
                    'method' => 'GET',
                    'redirect_url' => ($this->sandbox() ? self::SANDBOX_START : self::LIVE_START).rawurlencode($authority),
                    'ref_id' => $authority,
                ];
            }

            Log::warning('[Zarinpal] request failed', ['body' => $response->body()]);
        } catch (\Throwable $e) {
            Log::error('[Zarinpal] request exception: '.$e->getMessage());
        }

        throw new \RuntimeException('اتصال به درگاه زرین‌پال ناموفق بود.');
    }

    public function verify(GatewayOrder $order, array $callback): ?string
    {
        // لغو یا شکست در صفحه‌ی درگاه: «NOK»
        if (($callback['Status'] ?? '') !== 'OK') {
            return null;
        }

        // 🔴 مرجعِ بازگشتی باید همانی باشد که برای همین سفارش گرفتیم
        $authority = (string) ($callback['Authority'] ?? '');
        if ($authority === '' || $authority !== (string) $order->gatewayRefId()) {
            Log::warning('[Zarinpal] callback carries another authority', [
                'order' => $order->gatewayOrderId(),
            ]);

            return null;
        }

        $payload = [
            'merchant_id' => (string) ($this->config['merchant_id'] ?? ''),
            // مبلغ از سفارشِ خودمان، نه از بازگشتِ مرورگر
            'amount' => $this->toRial($order->gatewayAmount()),
            'authority' => $authority,
        ];

        try {
            $response = $this->sandbox()
                ? Http::acceptJson()->asJson()->connectTimeout(5)->timeout(30)->post(self::SANDBOX_VERIFY, $payload)
                : Http::acceptJson()->asJson()->connectTimeout(5)->timeout(30)->post(self::LIVE_VERIFY, $payload);
        } catch (\Throwable $e) {
            Log::error('[Zarinpal] verify exception: '.$e->getMessage());
            throw GatewayUnreachable::transport('zarinpal', 'verify', $e->getMessage());
        }

        if ($response->serverError()) {
            throw GatewayUnreachable::transport('zarinpal', 'verify', 'HTTP '.$response->status());
        }

        $code = (int) ($response->json('data.code') ?? $response->json('errors.code'));

        if (in_array($code, self::OK, true)) {
            $refId = $response->json('data.ref_id');
            if ($refId === null || $refId === '') {
                // تأیید شد ولی رهگیری نیامد: نامعلوم، نه ناموفق
                throw GatewayUnreachable::unmatched('zarinpal', 'کدِ رهگیری در پاسخِ تأیید نیامد');
            }

            return (string) $refId;
        }

        if ($code === self::AMOUNT_MISMATCH) {
            Log::warning('[Zarinpal] verified amount does not match the order', [
                'order' => $order->gatewayOrderId(),
                'expected' => $this->toRial($order->gatewayAmount()),
            ]);
            throw GatewayUnreachable::unmatched('zarinpal', 'مبلغِ پرداخت‌شده با مبلغِ سفارش یکی نیست');
        }

        // درگاه واقعاً پاسخ داده و پاسخش «نه» است
        Log::warning('[Zarinpal] verify refused', ['code' => $code]);

        return null;
    }

    /**
     * همان تأیید، بی بازگشتِ مرورگر — با مرجع و مبلغِ **خودمان** (مرحلهٔ ۱۲ · فاز ۲).
     *
     * زرین‌پال تأییدِ دوباره را می‌پذیرد: پرداختِ تأییدشده ۱۰۱ و همان کدِ رهگیری را برمی‌گرداند، پرداختِ تأییدنشده
     * همین حالا تأیید می‌شود، و پرداختی که انجام نشده یا بانک برگردانده «نه» است. مبلغِ ناهم‌خوان و پاسخِ ناقص
     * همچنان «نامعلوم».
     */
    public function reverify(GatewayOrder $order): ?string
    {
        if ((string) $order->gatewayRefId() === '') {
            return null;
        }

        return $this->verify($order, ['Status' => 'OK', 'Authority' => (string) $order->gatewayRefId()]);
    }

    private function sandbox(): bool
    {
        return (bool) ($this->config['sandbox'] ?? false);
    }

    private function toRial(float|string $amount): int
    {
        $amount = (int) round((float) $amount);

        return $this->currency === 'toman' ? $amount * 10 : $amount;
    }
}
