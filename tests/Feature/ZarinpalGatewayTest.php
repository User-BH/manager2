<?php

namespace Tests\Feature;

use App\Services\Payment\GatewayOrder;
use App\Services\Payment\GatewayUnreachable;
use App\Services\Payment\ZarinpalGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * درایورِ زرین‌پال (مرحلهٔ ۹ · فاز ۴) — جدا از جریانِ خرید.
 *
 * ⚠️ نشانی‌ها و کدها از مستنداتِ عمومیِ زرین‌پال‌اند؛ با `merchant_id` ِ واقعی
 * هنوز سنجیده نشده (ENVIRONMENT VERIFICATION REQUIRED). این‌جا رفتارِ درایور
 * با هر پاسخِ ممکن سنجیده می‌شود.
 */
class ZarinpalGatewayTest extends TestCase
{
    private function order(string $authority = 'A1'): GatewayOrder
    {
        return new class($authority) implements GatewayOrder
        {
            public ?string $requested = null;

            public function __construct(private string $authority) {}

            public function gatewayOrderId(): int
            {
                return 7;
            }

            public function gatewayAmount(): float
            {
                return 125000;
            }

            public function gatewayCallbackUrl(): string
            {
                return 'https://sakena.test/checkout/callback/X';
            }

            public function gatewayPayerPhone(): ?string
            {
                return '09120000000';
            }

            public function gatewayDescription(): string
            {
                return 'آزمون';
            }

            public function gatewayRefId(): ?string
            {
                return $this->authority;
            }

            public function markGatewayRequested(string $gateway, string $refId): void
            {
                $this->requested = $gateway.':'.$refId;
            }
        };
    }

    public function test_sandbox_mode_uses_the_sandbox_host_for_request_and_start_pay(): void
    {
        Http::fake(['https://sandbox.zarinpal.com/*' => Http::response(['data' => ['code' => 100, 'authority' => 'S1']])]);
        $order = $this->order();

        $redirect = (new ZarinpalGateway(['merchant_id' => 'm', 'sandbox' => true]))->request($order);

        $this->assertSame('https://sandbox.zarinpal.com/pg/StartPay/S1', $redirect['redirect_url']);
        $this->assertSame('zarinpal:S1', $order->requested);
        Http::assertSent(fn (Request $r) => $r['amount'] === 1_250_000 && $r['metadata'] === ['mobile' => '09120000000']);
    }

    public function test_a_refused_request_is_an_error_not_a_redirect(): void
    {
        Http::fake(['*' => Http::response(['data' => [], 'errors' => ['code' => -9]])]);

        $this->expectException(\RuntimeException::class);
        (new ZarinpalGateway(['merchant_id' => 'm']))->request($this->order());
    }

    /** ۱۰۱: «پیش‌تر تأیید شده» — بازگشتِ دوباره، همان رهگیری */
    public function test_already_verified_is_success_with_the_same_reference(): void
    {
        Http::fake(['*' => Http::response(['data' => ['code' => 101, 'ref_id' => 555]])]);

        $tracking = (new ZarinpalGateway(['merchant_id' => 'm']))->verify($this->order(), ['Authority' => 'A1', 'Status' => 'OK']);

        $this->assertSame('555', $tracking);
    }

    /** تأیید شد ولی رهگیری نیامد: پول شاید رفته — نامعلوم، نه ناموفق */
    public function test_success_without_a_reference_is_indeterminate(): void
    {
        Http::fake(['*' => Http::response(['data' => ['code' => 100]])]);

        $this->expectException(GatewayUnreachable::class);
        (new ZarinpalGateway(['merchant_id' => 'm']))->verify($this->order(), ['Authority' => 'A1', 'Status' => 'OK']);
    }

    /** مبلغِ تأیید از سفارش است؛ مبلغ یا مرجعِ مرورگر خوانده نمی‌شود */
    public function test_verification_never_takes_the_amount_from_the_callback(): void
    {
        Http::fake(['*' => Http::response(['data' => ['code' => 100, 'ref_id' => 1]])]);

        (new ZarinpalGateway(['merchant_id' => 'm']))->verify($this->order(), [
            'Authority' => 'A1', 'Status' => 'OK', 'amount' => 10, 'Amount' => 10,
        ]);

        Http::assertSent(fn (Request $r) => $r['amount'] === 1_250_000 && $r['authority'] === 'A1');
    }

    public function test_cancelled_or_foreign_returns_never_reach_the_gateway(): void
    {
        Http::fake();
        $gateway = new ZarinpalGateway(['merchant_id' => 'm']);

        $this->assertNull($gateway->verify($this->order(), ['Authority' => 'A1', 'Status' => 'NOK']));
        $this->assertNull($gateway->verify($this->order(), ['Authority' => 'A2', 'Status' => 'OK']));
        $this->assertNull($gateway->verify($this->order(), ['Status' => 'OK']));

        Http::assertNothingSent();
    }
}
