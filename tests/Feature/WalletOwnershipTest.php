<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Unit;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Account\Membership;
use App\Services\Charge\BillGenerator;
use App\Services\Units\TenureService;
use App\Services\Wallet\WalletService;
use App\Support\Jalali;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * کیفِ پول مالِ **حسابِ شخص** است (مرحلهٔ ۹ · اصلاحاتِ پس از فاز ۶؛ تصمیمِ کارفرما).
 *
 * شش آزمونِ خواسته‌شده: پایانِ عضویت مانده را عوض نمی‌کند؛ دسترسی می‌ماند؛
 * ساکنِ تازه نه می‌بیند نه خرج می‌کند؛ پس از مجتمعِ دیگر همان کیف؛ تغییرِ
 * `complex_id`/`unit_id` دسترسی نمی‌سازد؛ همزمانی (`WalletConcurrencyTest`، پردازه‌ی واقعی).
 */
class WalletOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $manager;

    private Unit $unit;

    private User $hosseini;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create(['name' => 'مجتمع الف']);
        $this->manager = User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true,
        ]);
        $this->unit = Unit::factory()->create(['complex_id' => $this->complex->id, 'unit_number' => '4']);
        $this->hosseini = User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => UserRole::Tenant, 'is_active' => true, 'phone' => '09128880001',
        ]);
        app(TenureService::class)->open($this->unit, $this->hosseini, ResidentRelation::Tenant, 100, now()->subMonths(6));

        app(WalletService::class)->credit($this->hosseini, $this->unit, 300000, WalletTransaction::SOURCE_ADJUSTMENT, null, 'شارژِ آقای حسینی');
    }

    /** ۱ و ۲ — مانده دست نمی‌خورد و صاحب همچنان به کیفش می‌رسد، حتی بی هیچ عضویتی. */
    public function test_ending_membership_keeps_the_balance_and_the_access(): void
    {
        $rowsBefore = WalletTransaction::withoutGlobalScopes()->count();

        $this->as($this->manager)->deleteJson("/api/v1/residents/{$this->hosseini->id}")->assertOk();

        $hosseini = User::withoutGlobalScopes()->find($this->hosseini->id);
        $this->assertNull($hosseini->complex_id);
        $this->assertSame(300000.0, app(WalletService::class)->balance($hosseini, $this->complex->id));
        // هیچ ردیفی ساخته، حذف یا جابه‌جا نشد
        $this->assertSame($rowsBefore, WalletTransaction::withoutGlobalScopes()->count());

        $this->as($hosseini)->getJson('/api/v1/wallet')->assertOk()
            ->assertJsonPath('wallets.0.balance', 300000)
            ->assertJsonPath('wallets.0.label', 'مجتمع الف');
        $this->as($hosseini)->getJson("/api/v1/wallet/{$this->complex->id}")->assertOk()
            ->assertJsonPath('balance', 300000)
            ->assertJsonCount(1, 'transactions');
    }

    /** ۳ — ساکنِ تازه‌ی همان واحد: نه مانده، نه گردش، نه پرداخت با پولِ ساکنِ قبلی. */
    public function test_the_next_tenant_can_neither_see_nor_spend_the_previous_tenants_wallet(): void
    {
        $this->as($this->manager)->deleteJson("/api/v1/residents/{$this->hosseini->id}")->assertOk();
        $bob = $this->joinUnit('09128880002');
        $bill = $this->bill(100000);

        $this->as($bob)->getJson('/api/v1/wallet')->assertOk()->assertJsonCount(0, 'wallets');
        $this->as($bob)->getJson("/api/v1/wallet/{$this->complex->id}")->assertOk()
            ->assertJsonPath('balance', 0)->assertJsonCount(0, 'transactions');
        $this->as($bob)->getJson("/api/v1/pay/{$bill->id}")->assertOk()->assertJsonPath('walletBalance', 0);
        $this->as($bob)->postJson("/api/v1/wallet/pay/{$bill->id}")->assertStatus(422);

        $this->assertSame(0.0, (float) $bill->fresh()->paid_amount);
        $this->assertSame(300000.0, app(WalletService::class)->balance($this->hosseini, $this->complex->id));
    }

    /** ۴ — پس از پیوستن به مجتمعِ دیگر، همان کیف؛ و جیبِ مجتمعِ الف قبضِ مجتمعِ ب را نمی‌پردازد (ادغام نه). */
    public function test_after_joining_another_complex_the_same_wallet_remains_and_pockets_do_not_merge(): void
    {
        $this->as($this->manager)->deleteJson("/api/v1/residents/{$this->hosseini->id}")->assertOk();

        $other = Complex::factory()->create(['name' => 'مجتمع ب']);
        $otherUnit = Unit::factory()->create(['complex_id' => $other->id]);
        [$invitation] = app(Membership::class)->invite($other, $this->hosseini->phone, UserRole::Tenant, $otherUnit, null);
        app(Membership::class)->accept($invitation, User::withoutGlobalScopes()->find($this->hosseini->id));
        $hosseini = User::withoutGlobalScopes()->find($this->hosseini->id);

        $wallets = $this->as($hosseini)->getJson('/api/v1/wallet')->assertOk()->json('wallets');
        $this->assertSame([['مجتمع الف', 300000]], array_map(fn ($w) => [$w['label'], $w['balance']], $wallets));

        $billB = Bill::withoutGlobalScopes()->create([
            'complex_id' => $other->id, 'unit_id' => $otherUnit->id, 'period' => Jalali::currentPeriod(),
            'base_amount' => 50000, 'total_amount' => 50000, 'paid_amount' => 0, 'due_date' => now()->addDays(10),
        ]);
        $this->as($hosseini)->postJson("/api/v1/wallet/pay/{$billB->id}")->assertStatus(422);
        $this->assertSame(0.0, (float) $billB->fresh()->paid_amount);
        $this->assertSame(300000.0, app(WalletService::class)->balance($hosseini, $this->complex->id));
    }

    /** ۵ — دست‌کاریِ `complex_id` یا `unit_id` (پارامتر یا حساب) کیفِ کسِ دیگری را باز نمی‌کند. */
    public function test_changing_complex_or_unit_never_opens_someone_elses_wallet(): void
    {
        $intruder = User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => UserRole::Owner, 'is_active' => true,
        ]);
        app(TenureService::class)->open($this->unit, $intruder, ResidentRelation::Owner);

        // هم‌واحد و هم‌مجتمع، با هر شناسه‌ای: فقط کیفِ خودش
        foreach (["/api/v1/wallet/{$this->complex->id}", '/api/v1/wallet/'.($this->complex->id + 1)] as $path) {
            $this->as($intruder)->getJson($path)->assertOk()->assertJsonCount(0, 'transactions');
        }
        $this->as($intruder)->getJson("/api/v1/wallet/members/{$this->hosseini->id}")->assertForbidden();

        // حتی با جابه‌جاییِ مستقیمِ مجتمع روی حساب
        $intruder->forceFill(['complex_id' => Complex::factory()->create()->id])->save();
        $this->as($intruder->fresh())->getJson("/api/v1/wallet/{$this->complex->id}")->assertOk()
            ->assertJsonCount(0, 'transactions');

        // مدیرِ همین مجتمع فقط بخشِ همین مجتمع را می‌بیند
        $this->as($this->manager)->getJson("/api/v1/wallet/members/{$this->hosseini->id}")->assertOk()
            ->assertJsonPath('balance', 300000);

        // و مدیرِ مجتمعِ دیگر صورت‌حسابِ اعضای این مجتمع را نمی‌گیرد
        $foreignManager = User::factory()->create([
            'complex_id' => Complex::factory()->create()->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true,
        ]);
        $this->as($foreignManager)->getJson("/api/v1/wallet/members/{$this->hosseini->id}")->assertNotFound();
    }

    /** بازگشتِ کاهشِ قبض به **پرداخت‌کنندگان**، از تازه‌ترین، و هرگز بیش از سهمِ هر کس. */
    public function test_a_bill_reduction_refunds_the_people_who_paid_it(): void
    {
        $partner = User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => UserRole::Owner, 'is_active' => true,
        ]);
        $bill = $this->bill(150000);

        $this->travel(-2)->days();
        $this->allocate($bill, $this->hosseini, 100000);
        $this->travelBack();
        $this->allocate($bill, $partner, 50000);
        $bill->forceFill(['paid_amount' => 150000])->save();

        // قاعده‌ای نیست، پس مبلغِ تازه صفر است: ۱۵۰٬۰۰۰ اضافه
        app(BillGenerator::class)->generate($this->complex, Jalali::currentPeriod());

        $wallet = app(WalletService::class);
        $this->assertSame(50000.0, $wallet->balance($partner, $this->complex->id));
        $this->assertSame(300000.0 + 100000.0, $wallet->balance($this->hosseini, $this->complex->id));
        // هیچ ریالی به واحد یا ساکنِ دیگری نرفت
        $this->assertSame(0.0, (float) $bill->fresh()->paid_amount);
    }

    /**
     * بازگشتِ **بخشی**: از تازه‌ترین پرداخت‌کننده شروع می‌شود و از سهمش بیشتر
     * نمی‌شود. (خرابکاری نشان داد آزمونِ بالا ترتیب را نمی‌سنجد، چون همه‌ی
     * اضافه برمی‌گردد و ترتیب فرقی نمی‌کند.)
     */
    public function test_a_partial_refund_goes_to_the_latest_payer_first_and_never_beyond_their_share(): void
    {
        $partner = User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => UserRole::Owner, 'is_active' => true,
        ]);
        $bill = $this->bill(150000);

        $this->travel(-2)->days();
        $this->allocate($bill, $this->hosseini, 100000);
        $this->travelBack();
        $this->allocate($bill, $partner, 50000);

        $wallet = app(WalletService::class);
        $before = $wallet->balance($this->hosseini, $this->complex->id);

        $this->assertSame(0.0, $wallet->refundBillOverpayment($bill, 70000, 'آزمونِ بازگشتِ بخشی'));

        $this->assertSame(50000.0, $wallet->balance($partner, $this->complex->id), 'تازه‌ترین پرداخت‌کننده اول و تا سقفِ سهمش.');
        $this->assertSame($before + 20000.0, $wallet->balance($this->hosseini, $this->complex->id), 'باقی‌مانده به پرداخت‌کننده‌ی قبلی.');
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    private function joinUnit(string $phone): User
    {
        $user = User::factory()->create(['complex_id' => null, 'role' => UserRole::Owner, 'is_active' => true, 'phone' => $phone]);
        [$invitation] = app(Membership::class)->invite($this->complex, $phone, UserRole::Tenant, $this->unit, $this->manager);
        app(Membership::class)->accept($invitation, $user);

        return $user->fresh();
    }

    private function bill(float $total): Bill
    {
        return Bill::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_id' => $this->unit->id, 'period' => Jalali::currentPeriod(),
            'base_amount' => $total, 'total_amount' => $total, 'paid_amount' => 0, 'due_date' => now()->addDays(10),
        ]);
    }

    private function allocate(Bill $bill, User $payer, float $amount): void
    {
        $payment = Payment::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_id' => $this->unit->id, 'bill_id' => $bill->id,
            'user_id' => $payer->id, 'amount' => $amount, 'method' => PaymentMethod::Online,
            'status' => PaymentStatus::Success, 'period' => $bill->period,
        ]);
        PaymentAllocation::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'payment_id' => $payment->id, 'bill_id' => $bill->id, 'amount' => $amount,
        ]);
    }

    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();

        return $this->actingAs($user);
    }
}
