<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Enums\OccupancyStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionPlan;
use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * بازگشت از درگاه بانکی.
 *
 * دو نقص واقعی را پوشش می‌دهد:
 *  ۱) بازگشت دوباره (رفرش یا دکمه‌ی back) تراکنش را دوباره تایید می‌کرد؛ با
 *     درگاه واقعی این یعنی پرداختِ موفق «ناموفق» علامت می‌خورد.
 *  ۲) مسیر بازگشت پشت میدل‌ور `auth` بود؛ اگر نشست تا لحظه‌ی بازگشت منقضی
 *     می‌شد، پول کم شده بود ولی قبض هرگز تسویه نمی‌شد.
 */
class GatewayCallbackTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $resident;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::create([
            'name' => 'مجتمع درگاه', 'slug' => 'gw-'.uniqid(), 'currency' => 'toman',
            'charge_due_day' => 10, 'payment_gateway' => 'fake',
        ]);

        $this->resident = User::create([
            'complex_id' => $this->complex->id, 'name' => 'ساکن', 'phone' => '09191110001',
            'role' => UserRole::Owner, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $this->unit = Unit::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_number' => '1', 'floor' => 1, 'area' => 100,
            'residents_count' => 2, 'coefficient' => 1,
            'occupancy_status' => OccupancyStatus::OwnerOccupied, 'uses_elevator' => true,
        ]);

        $this->resident->units()->attach($this->unit->id, [
            'complex_id' => $this->complex->id, 'relation' => 'owner',
            'share_percent' => 100, 'is_current' => true,
        ]);
    }

    private function makeBill(float $total = 500000, string $period = '1405-04'): Bill
    {
        return Bill::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_id' => $this->unit->id, 'period' => $period,
            'total_amount' => $total, 'paid_amount' => 0, 'status' => BillStatus::Unpaid, 'issued_at' => now(),
        ]);
    }

    /** شروع پرداخت آنلاین و برگرداندن تراکنشِ ساخته‌شده. */
    private function startPayment(Bill $bill): Payment
    {
        $this->actingAs($this->resident)->post("/pay/{$bill->id}/online");

        return Payment::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    /* ------------------- تکرار بازگشت (idempotency) ------------------- */

    public function test_a_repeated_callback_does_not_flip_a_successful_payment_to_failed(): void
    {
        $bill = $this->makeBill();
        $payment = $this->startPayment($bill);

        $this->actingAs($this->resident)->get("/pay/callback/{$payment->id}?ref={$payment->ref_id}")
            ->assertRedirectContains('payment=success');

        $trackingAfterFirst = $payment->fresh()->tracking_code;

        // رفرش صفحه‌ی بازگشت: نباید دوباره با درگاه تماس گرفته شود
        $this->actingAs($this->resident)->get("/pay/callback/{$payment->id}?ref={$payment->ref_id}")
            ->assertRedirectContains('payment=success');

        $payment->refresh();

        $this->assertSame(PaymentStatus::Success, $payment->status);
        // کد رهگیری هم نباید عوض شود، وگرنه رسید کاربر با سابقه نمی‌خواند
        $this->assertSame($trackingAfterFirst, $payment->tracking_code);
    }

    public function test_a_repeated_callback_does_not_credit_the_amount_twice(): void
    {
        // واحد یک قبض معوقِ دیگر هم دارد؛ اینجاست که تسویه‌ی دوباره خطرناک است،
        // چون مبلغ روی قبض بعدی می‌نشیند و اعتبار ساختگی می‌سازد.
        $paidBill = $this->makeBill(total: 200000, period: '1405-04');
        $otherBill = $this->makeBill(total: 300000, period: '1405-05');

        $payment = $this->startPayment($paidBill);

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($this->resident)->get("/pay/callback/{$payment->id}?ref={$payment->ref_id}");
        }

        $this->assertSame(200000.0, (float) $paidBill->fresh()->paid_amount);
        $this->assertSame(0.0, (float) $otherBill->fresh()->paid_amount, 'قبض دوم نباید از تسویه‌ی تکراری اعتبار بگیرد.');
        $this->assertSame(1, Payment::withoutGlobalScopes()->where('status', PaymentStatus::Success)->count());
    }

    public function test_a_failed_payment_is_not_revived_by_replaying_the_callback(): void
    {
        $bill = $this->makeBill();
        $payment = $this->startPayment($bill);

        // بازگشت با ref نادرست ⇒ ناموفق
        $this->actingAs($this->resident)->get("/pay/callback/{$payment->id}?ref=WRONG")
            ->assertRedirectContains('payment=failed');
        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);

        // حالا با ref درست دوباره تلاش می‌کند؛ تراکنش مرده نباید زنده شود
        $this->actingAs($this->resident)->get("/pay/callback/{$payment->id}?ref={$payment->ref_id}")
            ->assertRedirectContains('payment=failed');

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame(0.0, (float) $bill->fresh()->paid_amount);
    }

    /* ------------------- بازگشت بدون نشست ------------------- */

    public function test_the_callback_settles_the_bill_even_when_the_session_expired(): void
    {
        $bill = $this->makeBill();
        $payment = $this->startPayment($bill);

        // شبیه‌سازی انقضای نشست حین حضور کاربر روی صفحه‌ی بانک
        auth()->logout();
        session()->flush();

        $this->get("/pay/callback/{$payment->id}?ref={$payment->ref_id}")
            ->assertRedirectContains('payment=success');

        // مهم‌ترین بخش: پول کم شده، پس قبض باید تسویه شده باشد
        $this->assertSame(PaymentStatus::Success, $payment->fresh()->status);
        $this->assertSame(500000.0, (float) $bill->fresh()->paid_amount);
        $this->assertSame(BillStatus::Paid, $bill->fresh()->status);
    }

    public function test_another_logged_in_user_cannot_claim_someone_elses_callback(): void
    {
        $bill = $this->makeBill();
        $payment = $this->startPayment($bill);

        $stranger = User::create([
            'complex_id' => $this->complex->id, 'name' => 'غریبه', 'phone' => '09191110009',
            'role' => UserRole::Owner, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        // نبودِ نشست مجاز است، ولی نشستِ شخص دیگر نه
        $this->actingAs($stranger)->get("/pay/callback/{$payment->id}?ref={$payment->ref_id}")
            ->assertForbidden();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    /* ------------------- بازگشت خرید اشتراک ------------------- */

    public function test_a_repeated_subscription_callback_does_not_extend_the_period(): void
    {
        config(['subscription.gateway' => 'sandbox']);

        $admin = User::create([
            'complex_id' => $this->complex->id, 'name' => 'مدیر', 'phone' => '09191110002',
            'role' => UserRole::ComplexAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $this->actingAs($admin)->post('/subscription/checkout', ['plan' => 'pro']);
        $subscription = Subscription::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->actingAs($admin)->get("/subscription/callback/{$subscription->id}?ref={$subscription->ref_id}")
            ->assertRedirectContains('checkout=success');

        $endsAt = $subscription->fresh()->ends_at;

        $this->actingAs($admin)->get("/subscription/callback/{$subscription->id}?ref={$subscription->ref_id}")
            ->assertRedirectContains('checkout=success');

        $this->assertEquals($endsAt, $subscription->fresh()->ends_at, 'بازگشت دوباره نباید دوره را تمدید کند.');
        $this->assertSame(SubscriptionPlan::Pro, $subscription->fresh()->plan);
    }

    public function test_the_subscription_callback_works_without_a_session(): void
    {
        config(['subscription.gateway' => 'sandbox']);

        $admin = User::create([
            'complex_id' => $this->complex->id, 'name' => 'مدیر', 'phone' => '09191110003',
            'role' => UserRole::ComplexAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $this->actingAs($admin)->post('/subscription/checkout', ['plan' => 'pro']);
        $subscription = Subscription::withoutGlobalScopes()->latest('id')->firstOrFail();

        auth()->logout();
        session()->flush();

        $this->get("/subscription/callback/{$subscription->id}?ref={$subscription->ref_id}")
            ->assertRedirectContains('checkout=success');

        $this->assertSame('active', $subscription->fresh()->status);
    }
}
