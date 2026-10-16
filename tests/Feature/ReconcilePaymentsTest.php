<?php

namespace Tests\Feature;

use App\Enums\ComplexType;
use App\Enums\UserRole;
use App\Models\CheckoutIntent;
use App\Models\Complex;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscription\ComplexPurchase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔴 «پرداخت موفق بود ولی ثبت نشد» — بازیابیِ امن و بازپرداخت (مرحلهٔ ۱۲ · فاز ۲؛ بندِ ۵ ِ تصمیمِ قطعیِ کارفرما).
 *
 * کاربر نباید دوباره بپردازد و وضعیتِ مالی نباید نامعلوم بماند: `payments:reconcile` پرداختی را که بازگشتش نرسید از
 * خودِ درگاه دوباره می‌پرسد و از همان مسیرِ قفل‌دار کامل یا ناموفق می‌کند؛ قصدِ «پرداخت‌شده، ساخته‌نشده» یا کامل
 * می‌شود یا با مرجعِ بانکی بازپرداخت ثبت می‌شود — و پس از بازپرداخت هرگز چیزی نمی‌سازد.
 */
class ReconcilePaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const AUTHORITY = 'A00000000000000000000000000000088888';

    private const VERIFY_URL = 'https://payment.zarinpal.com/pg/v4/payment/verify.json';

    private Complex $complex;

    private User $manager;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'subscription.gateway' => 'zarinpal',
            'subscription.config.merchant_id' => 'test-merchant-id',
            'subscription.config.sandbox' => false,
        ]);

        $this->complex = Complex::factory()->create(['type' => ComplexType::Residential]);
        $this->manager = User::factory()->create(['complex_id' => $this->complex->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true, 'phone' => '09125558001']);
        $this->plan = Plan::create(['name' => 'پایه', 'slug' => 'base', 'complex_type' => ComplexType::Residential, 'price' => 200000, 'months' => 1, 'is_active' => true]);
    }

    /** @param  array<string, mixed>  $verify */
    private function verifyReturns(array $verify, int $status = 200): void
    {
        Http::fake([self::VERIFY_URL => Http::response($verify, $status)]);
    }

    private function strandedRenewal(int $minutesAgo = 20): Subscription
    {
        $subscription = Subscription::create([
            'complex_id' => $this->complex->id, 'user_id' => $this->manager->id, 'plan' => 'pro', 'plan_id' => $this->plan->id,
            'status' => 'pending', 'method' => 'online', 'amount' => 200000, 'months' => 1,
            'gateway' => 'zarinpal', 'ref_id' => self::AUTHORITY,
        ]);
        $subscription->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->saveQuietly();

        return $subscription;
    }

    private function strandedIntent(User $buyer, int $minutesAgo = 20): CheckoutIntent
    {
        $intent = app(ComplexPurchase::class)->begin($buyer, $this->plan, 'مجتمعِ بازیابی', ComplexType::Residential, null);
        $intent->markGatewayRequested('zarinpal', self::AUTHORITY);
        $intent->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

        return $intent;
    }

    private function initialBuyer(): User
    {
        return User::factory()->create(['complex_id' => null, 'role' => UserRole::Owner, 'is_active' => true, 'phone' => '09125558002']);
    }

    /** 🔴 بازگشت نرسید ولی پول رفت: بازبینی همان تمدید را فعال می‌کند — با مبلغِ خودمان؛ پرداختِ دوباره لازم نیست. */
    public function test_a_paid_renewal_whose_return_never_arrived_is_activated(): void
    {
        $this->verifyReturns(['data' => ['code' => 101, 'ref_id' => 70700001]]);
        $renewal = $this->strandedRenewal();

        $this->artisan('payments:reconcile')->assertSuccessful();

        $fresh = $renewal->fresh();
        $this->assertSame(['active', '70700001'], [$fresh->status, $fresh->tracking_code]);
        Http::assertSent(fn ($r) => $r['authority'] === self::AUTHORITY && $r['amount'] === 2_000_000);

        // بازگشتِ دیرِ کاربر پس از بازبینی چیزی را دوباره نمی‌سازد
        $this->get("/subscription/callback/{$renewal->id}?Authority=".self::AUTHORITY.'&Status=OK')->assertRedirectContains('checkout=success');
        $this->assertSame(1, Subscription::where('status', 'active')->count());
    }

    /** درگاه قطعاً می‌گوید پرداختی نیست ← ناموفق؛ پاسخ نداد ← دست نمی‌خورد؛ هنوز تازه ← اصلاً پرسیده نمی‌شود. */
    public function test_unpaid_undecided_and_fresh_renewals(): void
    {
        $this->verifyReturns(['data' => [], 'errors' => ['code' => -51]]);
        $unpaid = $this->strandedRenewal();
        $fresh = $this->strandedRenewal(minutesAgo: 5);

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame('failed', $unpaid->fresh()->status);
        $this->assertSame('pending', $fresh->fresh()->status, 'پرداختِ تازه به فرصتِ بازگشتِ خودِ کاربر نیاز دارد.');
        Http::assertSentCount(1);
    }

    public function test_an_unreachable_gateway_leaves_the_payment_undecided(): void
    {
        $this->verifyReturns([], 502);
        $renewal = $this->strandedRenewal();

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame('pending', $renewal->fresh()->status);
    }

    /** خریدِ مجتمعِ تازه: بازگشت نرسید ولی پول رفت ← مجتمع، اشتراک و مدیر، یک بار. */
    public function test_a_paid_purchase_whose_return_never_arrived_creates_the_complex_once(): void
    {
        $this->verifyReturns(['data' => ['code' => 100, 'ref_id' => 70700002]]);
        $buyer = $this->initialBuyer();
        $intent = $this->strandedIntent($buyer);

        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(CheckoutIntent::COMPLETED, $intent->fresh()->status);
        $this->assertSame(1, Complex::where('name', 'مجتمعِ بازیابی')->count());
        $this->assertSame(UserRole::ComplexAdmin, $buyer->fresh()->role);
    }

    /** 🔴 قصدی که به درگاه رسیده با خریدِ تازه لغو نمی‌شود — شاید پرداخت شده باشد. */
    public function test_a_new_purchase_does_not_cancel_an_intent_that_reached_the_gateway(): void
    {
        $buyer = $this->initialBuyer();
        $reached = $this->strandedIntent($buyer);
        $draft = app(ComplexPurchase::class)->begin($buyer, $this->plan, 'پیش‌نویس', ComplexType::Residential, null);

        app(ComplexPurchase::class)->begin($buyer, $this->plan, 'سومی', ComplexType::Residential, null);

        $this->assertSame(CheckoutIntent::PENDING, $reached->fresh()->status);
        $this->assertSame(CheckoutIntent::CANCELLED, $draft->fresh()->status);
    }

    /** 🔴 «پرداخت‌شده، ساخته‌نشده»: تا حساب نمی‌تواند همان می‌ماند؛ فعال که شد بازبینی کاملش می‌کند — بی پرداختِ دوباره. */
    public function test_a_paid_but_unfulfilled_purchase_is_completed_once_the_account_can(): void
    {
        $buyer = $this->initialBuyer();
        $intent = $this->strandedIntent($buyer);
        $buyer->forceFill(['is_active' => false])->save();

        $paid = app(ComplexPurchase::class)->complete($intent, '70700003');
        $this->assertSame(CheckoutIntent::PAID, $paid->status);

        $this->artisan('payments:reconcile', ['--after' => 60])->assertSuccessful();
        $this->assertSame(CheckoutIntent::PAID, $intent->fresh()->status);

        $buyer->forceFill(['is_active' => true])->save();
        $this->artisan('payments:reconcile', ['--after' => 60])->assertSuccessful();

        $done = $intent->fresh();
        $this->assertSame(CheckoutIntent::COMPLETED, $done->status);
        $this->assertSame('70700003', $done->tracking_code);
        $this->assertSame('active', Subscription::findOrFail($done->subscription_id)->status);
    }

    /** 🔴 بازپرداخت: فقط ادمینِ کل، فقط قصدِ `paid`، یک بار — و پس از آن هرگز مجتمعی از همان پول. */
    public function test_a_refund_is_recorded_once_and_the_payment_never_becomes_a_complex(): void
    {
        $buyer = $this->initialBuyer();
        $intent = $this->strandedIntent($buyer);
        $buyer->forceFill(['is_active' => false])->save();
        app(ComplexPurchase::class)->complete($intent, '70700004');

        $super = User::factory()->create(['complex_id' => null, 'role' => UserRole::SuperAdmin, 'is_active' => true, 'phone' => '09125558003']);
        $this->withConfirmedPassword();

        $this->actingAs($this->manager)->postJson("/api/v1/system/checkouts/{$intent->public_id}/refund", ['reference' => 'REF-123456'])->assertForbidden();
        $this->actingAs($super)->postJson("/api/v1/system/checkouts/{$intent->public_id}/refund", ['reference' => ''])->assertStatus(422);

        $listed = $this->actingAs($super)->getJson('/api/v1/system/subscriptions')->assertOk()->json('checkouts');
        $this->assertSame([$intent->public_id], array_column($listed, 'id'));

        $this->actingAs($super)->postJson("/api/v1/system/checkouts/{$intent->public_id}/refund", ['reference' => 'REF-123456'])->assertOk();
        $refunded = $intent->fresh();
        $this->assertSame([CheckoutIntent::REFUNDED, 'REF-123456', $super->id], [$refunded->status, $refunded->refund_reference, $refunded->refunded_by]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'checkout.refunded', 'subject_id' => $intent->id]);

        // دوباره نه؛ و حسابِ دوباره‌فعال از همان پول مجتمع نمی‌گیرد
        $this->actingAs($super)->postJson("/api/v1/system/checkouts/{$intent->public_id}/refund", ['reference' => 'REF-123456'])->assertStatus(422);
        $buyer->forceFill(['is_active' => true])->save();
        $this->artisan('payments:reconcile', ['--after' => 60])->assertSuccessful();
        $this->assertSame(CheckoutIntent::REFUNDED, $intent->fresh()->status);
        $this->assertSame(0, Complex::where('name', 'مجتمعِ بازیابی')->count());
    }

    /** درگاهی که دوباره‌پرسیدن ندارد (ملت) دست نمی‌خورد؛ فقط `payments:stale` گزارشش می‌کند. */
    public function test_a_gateway_that_cannot_be_asked_again_is_left_for_a_human(): void
    {
        config(['subscription.gateway' => 'mellat', 'subscription.config.terminal_id' => '123']);
        Http::fake();
        $renewal = $this->strandedRenewal();

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame('pending', $renewal->fresh()->status);
        Http::assertNothingSent();
    }

    /** 🔴 کارِ شبانه دیگر پرداختی را که به درگاه رسیده «رهاشده» نمی‌کند — فقط آن‌که هرگز نرسید. */
    public function test_the_nightly_job_only_abandons_payments_that_never_reached_the_gateway(): void
    {
        $reached = $this->strandedRenewal(minutesAgo: 60 * 24);
        $never = Subscription::create([
            'complex_id' => $this->complex->id, 'user_id' => $this->manager->id, 'plan' => 'pro',
            'status' => 'pending', 'method' => 'online', 'amount' => 200000, 'months' => 1,
        ]);
        $never->forceFill(['created_at' => now()->subDay()])->saveQuietly();

        $this->artisan('subscriptions:maintain')->assertSuccessful();

        $this->assertSame('pending', $reached->fresh()->status, 'پرداختی که شاید انجام شده بی پرسیدن از درگاه ناموفق شد.');
        $this->assertSame('failed', $never->fresh()->status);
    }
}
