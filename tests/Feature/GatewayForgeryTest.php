<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Enums\OccupancyStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionPlan;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * جعلِ بازگشتِ بانک (مرحلهٔ ۵ · فاز ۹ · بندِ ۶۹).
 *
 * 🔴 نقص: هیچ‌کدام از دو درگاه تأییدِ بانک را به **سفارشِ خودمان** گره
 * نمی‌زدند. همه‌ی مقادیرِ بازگشت از مرورگرِ کاربر می‌آیند:
 *
 *  - ملت `SaleOrderId` را از خودِ بازگشت می‌خواند؛
 *  - سامان هر `RefNum`ی را تأیید می‌کرد و مبلغ را نمی‌سنجید، و کدِ ۲
 *    («از قبل تأیید شده») را هم موفق می‌دانست.
 *
 * یعنی کسی که قبضِ ارزانی را می‌پرداخت و بازگشتش را رها می‌کرد، می‌توانست
 * شناسه‌های همان تراکنش را به بازگشتِ قبضِ گران بفرستد. بانک «تأیید» می‌گفت
 * — چون آن تراکنش واقعاً روی همین ترمینال انجام شده بود — و قبضِ گران با
 * پولِ ارزان تسویه می‌شد. هیچ تستی سامان را اصلاً تأیید نمی‌کرد.
 */
class GatewayForgeryTest extends TestCase
{
    use RefreshDatabase;

    private const SAMAN_VERIFY = 'https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg/VerifyTransaction';

    private Complex $complex;

    private User $resident;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::create(['type' => 'residential',
            'name' => 'مجتمع جعل', 'slug' => 'forge', 'currency' => 'toman',
            'charge_due_day' => 10, 'payment_gateway' => 'mellat',
            'gateway_config' => ['terminal_id' => '111', 'username' => 'u', 'password' => 'p'],
        ]);

        $this->resident = User::create([
            'complex_id' => $this->complex->id, 'name' => 'ساکن', 'phone' => '09191110081',
            'role' => UserRole::Owner, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $this->unit = Unit::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_number' => '1', 'floor' => 1, 'area' => 100,
            'residents_count' => 2, 'coefficient' => 1,
            'occupancy_status' => OccupancyStatus::OwnerOccupied, 'uses_elevator' => true,
        ]);

        $this->resident->units()->attach($this->unit->id, [
            'complex_id' => $this->complex->id, 'relation' => 'owner', 'share_percent' => 100, 'is_current' => true,
        ]);

        Subscription::create([
            'complex_id' => $this->complex->id, 'user_id' => $this->resident->id,
            'plan' => SubscriptionPlan::Pro, 'status' => 'active', 'method' => 'receipt',
            'amount' => 100, 'months' => 12, 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(),
        ]);
    }

    private function useSaman(): void
    {
        $this->complex->update(['payment_gateway' => 'saman', 'gateway_config' => ['terminal_id' => '222']]);
    }

    /** @return array{0: Bill, 1: Payment} */
    private function pending(float $amount, string $gateway = 'mellat', string $period = '1405-04'): array
    {
        $bill = Bill::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_id' => $this->unit->id, 'period' => $period,
            'total_amount' => $amount, 'paid_amount' => 0, 'status' => BillStatus::Unpaid, 'issued_at' => now(),
        ]);

        $payment = Payment::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_id' => $this->unit->id, 'bill_id' => $bill->id,
            'user_id' => $this->resident->id, 'amount' => $amount, 'method' => PaymentMethod::Online,
            'status' => PaymentStatus::Pending, 'period' => $period, 'gateway' => $gateway, 'ref_id' => 'REF-'.$bill->id,
        ]);

        return [$bill, $payment];
    }

    /** @param  array<string, string|int>  $query */
    private function returnFromBank(Payment $payment, array $query): TestResponse
    {
        return $this->get('/pay/callback/'.$payment->id.'?'.http_build_query($query));
    }

    private function samanVerifies(int $rial, int $code = 0): void
    {
        Http::fake([self::SAMAN_VERIFY => Http::response([
            'ResultCode' => $code,
            'TransactionDetail' => ['OrginalAmount' => $rial, 'AffectiveAmount' => $rial],
        ])]);
    }

    /* ── ملت ─────────────────────────────────────────────────────────────── */

    /** 🔴 فروشِ سفارشِ دیگر (ارزان) به بازگشتِ این سفارش (گران) چسبانده می‌شود. */
    public function test_mellat_refuses_a_sale_from_another_order_without_asking_the_bank(): void
    {
        Http::fake(['*' => Http::response('<return>0</return>')]);
        [$cheapBill, $cheap] = $this->pending(10000, period: '1405-03');
        [$bill, $expensive] = $this->pending(5000000);

        $this->returnFromBank($expensive, ['ResCode' => '0', 'SaleOrderId' => $cheap->id, 'SaleReferenceId' => '9001']);

        $this->assertSame(PaymentStatus::Failed, $expensive->fresh()->status);
        $this->assertSame(BillStatus::Unpaid, $bill->fresh()->status);
        // فروشِ بیگانه اصلاً به بانک فرستاده نشد، پس تأیید هم نشد
        Http::assertNothingSent();
    }

    /** شماره‌ی سفارشِ تأیید همیشه مالِ خودمان است، حتی اگر بازگشت چیزی نفرستد. */
    public function test_mellat_verifies_with_our_own_order_number(): void
    {
        Http::fake(['*' => Http::response('<return>0</return>')]);
        [$bill, $payment] = $this->pending(500000);

        $this->returnFromBank($payment, ['ResCode' => '0', 'SaleReferenceId' => '9002']);

        $this->assertSame(PaymentStatus::Success, $payment->fresh()->status);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->body(), 'bpVerifyRequest')
            && str_contains($r->body(), '<saleOrderId>'.$payment->id.'</saleOrderId>'));
    }

    /* ── سامان ───────────────────────────────────────────────────────────── */

    public function test_saman_refuses_a_callback_for_another_order_without_asking_the_bank(): void
    {
        $this->useSaman();
        $this->samanVerifies(50000000);
        [, $payment] = $this->pending(5000000, 'saman');

        $this->returnFromBank($payment, ['State' => 'OK', 'RefNum' => 'R-1', 'ResNum' => $payment->id + 1]);

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        Http::assertNothingSent();
    }

    /**
     * 🔴 همان حمله با `ResNum`ِ حذف‌شده: بانک تراکنشِ ارزان را تأیید می‌کند،
     * ولی مبلغش با این سفارش نمی‌خواند. تسویه نمی‌شود و «ناموفق» هم
     * نمی‌شود — پولِ واقعی جابه‌جا شده و باید بررسی شود.
     */
    public function test_saman_does_not_settle_when_the_verified_amount_differs(): void
    {
        $this->useSaman();
        $this->samanVerifies(100000); // ۱۰٬۰۰۰ تومان
        [$bill, $payment] = $this->pending(5000000, 'saman');

        $this->returnFromBank($payment, ['State' => 'OK', 'RefNum' => 'R-CHEAP']);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(BillStatus::Unpaid, $bill->fresh()->status);

        $audit = AuditLog::where('action', 'payment.indeterminate')->sole();
        $this->assertSame('match', $audit->properties['stage']);
    }

    public function test_saman_treats_a_missing_amount_as_undecided_not_failed(): void
    {
        $this->useSaman();
        Http::fake([self::SAMAN_VERIFY => Http::response(['ResultCode' => 0])]);
        [, $payment] = $this->pending(500000, 'saman');

        $this->returnFromBank($payment, ['State' => 'OK', 'RefNum' => 'R-2']);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(1, AuditLog::where('action', 'payment.indeterminate')->count());
    }

    public function test_saman_settles_the_real_payment(): void
    {
        $this->useSaman();
        $this->samanVerifies(5000000); // ۵۰۰٬۰۰۰ تومان به ریال
        [$bill, $payment] = $this->pending(500000, 'saman');

        $this->returnFromBank($payment, ['State' => 'OK', 'RefNum' => 'R-3', 'ResNum' => $payment->id]);

        $this->assertSame(PaymentStatus::Success, $payment->fresh()->status);
        $this->assertSame('R-3', $payment->fresh()->tracking_code);
        $this->assertSame(BillStatus::Paid, $bill->fresh()->status);
    }

    /**
     * 🔴 کدِ ۲ سامان («از قبل تأیید شده»): رهگیریِ قبضِ A به بازگشتِ قبضِ B
     * با همان مبلغ. بانک دوباره «تأیید» می‌گوید؛ یک پول نباید دو قبض را بپردازد.
     */
    public function test_one_bank_transaction_never_pays_two_bills(): void
    {
        $this->useSaman();
        $this->samanVerifies(5000000);
        [$billA, $a] = $this->pending(500000, 'saman', '1405-03');
        [$billB, $b] = $this->pending(500000, 'saman', '1405-04');

        $this->returnFromBank($a, ['State' => 'OK', 'RefNum' => 'R-SAME', 'ResNum' => $a->id]);
        $this->assertSame(PaymentStatus::Success, $a->fresh()->status);

        $this->samanVerifies(5000000, code: 2);
        $this->returnFromBank($b, ['State' => 'OK', 'RefNum' => 'R-SAME']);

        $this->assertSame(PaymentStatus::Failed, $b->fresh()->status);
        $this->assertSame(BillStatus::Unpaid, $billB->fresh()->status);
        $this->assertSame(BillStatus::Paid, $billA->fresh()->status);
    }

    /** همان قاعده بینِ قبض و اشتراک، که ممکن است یک ترمینال داشته باشند. */
    public function test_a_bill_payments_tracking_code_cannot_activate_a_subscription(): void
    {
        $this->useSaman();
        $this->samanVerifies(5000000);
        [, $a] = $this->pending(500000, 'saman');
        $this->returnFromBank($a, ['State' => 'OK', 'RefNum' => 'R-SHARED', 'ResNum' => $a->id]);
        $this->assertSame(PaymentStatus::Success, $a->fresh()->status);

        config(['subscription.gateway' => 'saman', 'subscription.config' => ['terminal_id' => '222']]);
        $subscription = Subscription::create([
            'complex_id' => $this->complex->id, 'user_id' => $this->resident->id,
            'plan' => SubscriptionPlan::Pro, 'status' => 'pending', 'method' => 'online',
            'amount' => 500000, 'months' => 12,
        ]);

        $this->samanVerifies(5000000, code: 2);
        $this->get('/subscription/callback/'.$subscription->id.'?'.http_build_query(['State' => 'OK', 'RefNum' => 'R-SHARED']))
            ->assertRedirect('/account?checkout=failed');

        $this->assertSame('failed', $subscription->fresh()->status);
    }
}
