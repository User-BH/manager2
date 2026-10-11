<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\ComplexMembership;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Unit;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Account\Membership;
use App\Services\Account\MembershipLedger;
use App\Services\Charge\BillGenerator;
use App\Services\Units\TenureService;
use App\Services\Wallet\WalletService;
use App\Support\Jalali;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * یک کیفِ پولِ **شخصی** برای هر حساب (مرحلهٔ ۹ · تصمیمِ نهاییِ کارفرما).
 *
 * یک مانده در هر داشبورد؛ خرج در یک مجتمع همه‌جا کم می‌کند؛ پایانِ عضویت و
 * پیوستن به مجتمعِ دیگر کیف را نه عوض می‌کند نه تکثیر؛ ساکنِ تازه نه می‌بیند نه
 * خرج می‌کند؛ هیچ شناسه‌ای از کلاینت کیفِ دیگری را باز نمی‌کند؛ مدیر کیفِ شخصیِ
 * عضو را نمی‌بیند؛ تکرارِ درخواست یک برداشت است. هم‌زمانی: `WalletConcurrencyTest`.
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

    /** پایانِ عضویت مانده را عوض نمی‌کند و صاحب همچنان به کیفش می‌رسد، حتی بی هیچ عضویتی. */
    public function test_ending_membership_keeps_the_balance_and_the_access(): void
    {
        $rowsBefore = WalletTransaction::withoutGlobalScopes()->count();

        $this->as($this->manager)->deleteJson("/api/v1/residents/{$this->hosseini->id}")->assertOk();

        $hosseini = User::withoutGlobalScopes()->find($this->hosseini->id);
        $this->assertNull($hosseini->complex_id);
        $this->assertSame(300000.0, app(WalletService::class)->balance($hosseini));
        // هیچ ردیفی ساخته، حذف یا جابه‌جا نشد
        $this->assertSame($rowsBefore, WalletTransaction::withoutGlobalScopes()->count());

        $this->as($hosseini)->getJson('/api/v1/wallet')->assertOk()
            ->assertJsonCount(1, 'wallets')
            ->assertJsonPath('wallets.0.balance', 300000);
        $this->as($hosseini)->getJson('/api/v1/wallet/statement')->assertOk()
            ->assertJsonPath('balance', 300000)
            ->assertJsonCount(1, 'transactions')
            ->assertJsonPath('transactions.0.complexName', 'مجتمع الف');
    }

    /** ساکنِ تازه‌ی همان واحد: نه مانده، نه گردش، نه پرداخت با پولِ ساکنِ قبلی. */
    public function test_the_next_tenant_can_neither_see_nor_spend_the_previous_tenants_wallet(): void
    {
        $this->as($this->manager)->deleteJson("/api/v1/residents/{$this->hosseini->id}")->assertOk();
        $bob = $this->joinUnit('09128880002');
        $bill = $this->bill(100000);

        $this->as($bob)->getJson('/api/v1/wallet')->assertOk()->assertJsonPath('wallets.0.balance', 0);
        $this->as($bob)->getJson('/api/v1/wallet/statement')->assertOk()
            ->assertJsonPath('balance', 0)->assertJsonCount(0, 'transactions');
        $this->as($bob)->getJson("/api/v1/pay/{$bill->id}")->assertOk()->assertJsonPath('walletBalance', 0);
        $this->as($bob)->postJson("/api/v1/wallet/pay/{$bill->id}")->assertStatus(422);

        $this->assertSame(0.0, (float) $bill->fresh()->paid_amount);
        $this->assertSame(300000.0, app(WalletService::class)->balance($this->hosseini));
    }

    /**
     * یک حساب، یک مانده در هر داشبورد (تصمیمِ کارفرما: «سه نمایش به یک کیفِ
     * canonical»). اعتبارِ ثبت‌شده در دو مجتمع جمعِ همان یک کیف است و در هر
     * زمینه‌ی فعال — مجتمعِ الف، مجتمعِ ب، حسابِ اولیه — همان عدد برمی‌گردد.
     *
     * ⚠️ عضویتِ هم‌زمان در چند مجتمع هنوز مجاز نیست (۱۰/۳)؛ زمینه‌ها با پایانِ عضویت و
     * پیوستن به مجتمعِ بعدی از دفترِ عضویت ساخته می‌شوند (۱۰/۲).
     */
    public function test_one_account_sees_one_balance_in_every_complex_context(): void
    {
        [$other, $otherUnit] = $this->otherComplex();
        app(WalletService::class)->credit($this->hosseini, $otherUnit, 200000, WalletTransaction::SOURCE_ADJUSTMENT);

        $ledger = app(MembershipLedger::class);
        foreach ([$this->complex, $other, null] as $complex) {
            $context = $complex?->id;
            if ($this->hosseini->fresh()->complex_id !== $context) {
                $ledger->end($this->hosseini, ComplexMembership::END_REMOVED);
                if ($complex !== null) {
                    $ledger->start($this->hosseini, $complex, UserRole::Tenant, ComplexMembership::SOURCE_DIRECT);
                }
            }
            $response = $this->as($this->hosseini->fresh())->getJson('/api/v1/wallet')->assertOk();
            $this->assertSame([500000], array_column($response->json('wallets'), 'balance'), "زمینه‌ی {$context}");
            $this->as($this->hosseini->fresh())->getJson('/api/v1/wallet/statement')->assertOk()
                ->assertJsonPath('balance', 500000)->assertJsonCount(2, 'transactions');
        }
    }

    /**
     * پس از رفتن از الف و پیوستن به ب: همان کیف (هیچ کیف یا ردیفِ تازه‌ای ساخته
     * نمی‌شود) و اعتبارِ الف قبضِ مجازِ ب را می‌پردازد؛ مانده همه‌جا کم می‌شود و
     * ردیفِ برداشت مجتمع و قبضِ ب را نشان می‌دهد.
     */
    public function test_after_joining_another_complex_the_same_wallet_pays_its_bills(): void
    {
        $this->as($this->manager)->deleteJson("/api/v1/residents/{$this->hosseini->id}")->assertOk();
        [$other, $otherUnit] = $this->otherComplex();
        $rowsBefore = WalletTransaction::withoutGlobalScopes()->count();

        [$invitation] = app(Membership::class)->invite($other, $this->hosseini->phone, UserRole::Tenant, $otherUnit, null);
        app(Membership::class)->accept($invitation, User::withoutGlobalScopes()->find($this->hosseini->id));
        $hosseini = User::withoutGlobalScopes()->find($this->hosseini->id);

        $this->assertSame($rowsBefore, WalletTransaction::withoutGlobalScopes()->count(), 'پیوستن کیف یا ردیفِ تازه ساخت.');
        $this->as($hosseini)->getJson('/api/v1/wallet')->assertOk()->assertJsonPath('wallets.0.balance', 300000);

        $billB = Bill::withoutGlobalScopes()->create([
            'complex_id' => $other->id, 'unit_id' => $otherUnit->id, 'period' => Jalali::currentPeriod(),
            'base_amount' => 50000, 'total_amount' => 50000, 'paid_amount' => 0, 'due_date' => now()->addDays(10),
        ]);
        $this->as($hosseini)->postJson("/api/v1/wallet/pay/{$billB->id}")->assertOk()
            ->assertJsonPath('paid', 50000)->assertJsonPath('balance', 250000);

        $this->assertSame(50000.0, (float) $billB->fresh()->paid_amount);
        $this->assertSame(250000.0, app(WalletService::class)->balance($hosseini));
        $debit = WalletTransaction::withoutGlobalScopes()->where('direction', WalletTransaction::DEBIT)->sole();
        $this->assertSame([$other->id, $otherUnit->id, $billB->id, $hosseini->id], [$debit->complex_id, $debit->unit_id, $debit->bill_id, $debit->user_id]);
        $this->assertSame(250000.0, (float) $debit->balance_after);

        // و در زمینه‌ی حسابِ اولیه هم همان مانده‌ی کم‌شده
        app(MembershipLedger::class)->end($hosseini, ComplexMembership::END_REMOVED);
        $this->as($hosseini->fresh())->getJson('/api/v1/wallet')->assertOk()->assertJsonPath('wallets.0.balance', 250000);
    }

    /**
     * هیچ شناسه‌ای از کلاینت کیفِ کسِ دیگری را انتخاب نمی‌کند: پارامترِ نشانی، بدنه،
     * قبضِ دیگری، یا جابه‌جاییِ مجتمعِ حساب. مدیر هم کیفِ شخصیِ عضو را نمی‌بیند.
     */
    public function test_no_identifier_from_the_client_opens_someone_elses_wallet(): void
    {
        $intruder = User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => UserRole::Owner, 'is_active' => true,
        ]);
        app(TenureService::class)->open($this->unit, $intruder, ResidentRelation::Owner);
        [$other] = $this->otherComplex();

        foreach ([
            '/api/v1/wallet/statement?user_id='.$this->hosseini->id,
            '/api/v1/wallet/statement?complex_id='.$this->complex->id.'&account_id='.$this->hosseini->id,
            '/api/v1/wallet/'.$this->complex->id,
        ] as $path) {
            $response = $this->as($intruder)->getJson($path);
            $this->assertContains($response->status(), [200, 404], $path);
            $this->assertStringNotContainsString('300000', (string) $response->getContent(), $path);
        }
        $this->as($intruder)->getJson('/api/v1/wallet/statement')->assertOk()->assertJsonCount(0, 'transactions');
        $this->as($intruder)->getJson("/api/v1/wallet/members/{$this->hosseini->id}")->assertForbidden();

        // پرداختِ قبضِ هم‌واحد با کیفِ خودش: کیفِ حسینی دست نمی‌خورد، با هر بدنه‌ای
        $bill = $this->bill(100000);
        $this->as($intruder)->postJson("/api/v1/wallet/pay/{$bill->id}", [
            'user_id' => $this->hosseini->id, 'owner_id' => $this->hosseini->id, 'wallet_id' => $this->hosseini->id,
        ])->assertStatus(422);
        $this->assertSame(300000.0, app(WalletService::class)->balance($this->hosseini));

        // پیوستن به مجتمعِ دیگر هم چیزی باز نمی‌کند (جابه‌جاییِ مستقیم از ۱۰/۲ ناممکن است)
        app(MembershipLedger::class)->end($intruder, ComplexMembership::END_REMOVED);
        app(MembershipLedger::class)->start($intruder, $other, UserRole::Owner, ComplexMembership::SOURCE_DIRECT);
        $this->as($intruder->fresh())->getJson('/api/v1/wallet/statement')->assertOk()->assertJsonCount(0, 'transactions');

        // مدیرِ همین مجتمع: فقط ردیف‌های همین مجتمع، بی مانده‌ی شخصی
        $member = $this->as($this->manager)->getJson("/api/v1/wallet/members/{$this->hosseini->id}")->assertOk();
        $member->assertJsonPath('balance', null)->assertJsonPath('transactions.0.balanceAfter', null);
        $this->as($this->manager)->getJson('/api/v1/wallet')->assertOk()->assertJsonPath('members.0.balance', null);

        // مدیرِ مجتمعِ دیگر: ۴۰۴
        $foreignManager = User::factory()->create([
            'complex_id' => $other->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true,
        ]);
        $this->as($foreignManager)->getJson("/api/v1/wallet/members/{$this->hosseini->id}")->assertNotFound();
    }

    /** مدیر گردشِ عضو در مجتمعِ دیگر را نمی‌بیند — حتی اگر عضوِ همین مجتمع باشد. */
    public function test_a_manager_sees_only_this_complexes_rows_of_a_member(): void
    {
        [, $otherUnit] = $this->otherComplex();
        app(WalletService::class)->credit($this->hosseini, $otherUnit, 200000, WalletTransaction::SOURCE_ADJUSTMENT, null, 'اعتبارِ مجتمعِ ب');

        $rows = $this->as($this->manager)->getJson("/api/v1/wallet/members/{$this->hosseini->id}")->assertOk()->json('transactions');
        $this->assertCount(1, $rows);
        $this->assertStringNotContainsString('اعتبارِ مجتمعِ ب', (string) json_encode($rows, JSON_UNESCAPED_UNICODE));
    }

    /**
     * کلیکِ دوباره یا retry با همان `Idempotency-Key`: یک برداشت، نه دو. کلیدِ
     * یکسان برای قبضِ دیگر رد می‌شود؛ بی کلید، پرداختِ دوم فقط بدهیِ باقی را می‌پردازد.
     */
    public function test_a_repeated_payment_with_the_same_key_is_recorded_once(): void
    {
        $bill = $this->bill(100000);
        $headers = ['Idempotency-Key' => 'pay-key-00000001'];

        $this->as($this->hosseini)->postJson("/api/v1/wallet/pay/{$bill->id}", ['amount' => 30000], $headers)
            ->assertOk()->assertJsonPath('paid', 30000);
        $this->as($this->hosseini)->postJson("/api/v1/wallet/pay/{$bill->id}", ['amount' => 30000], $headers)
            ->assertOk()->assertJsonPath('paid', 30000)->assertJsonPath('balance', 270000);

        $this->assertSame(1, WalletTransaction::withoutGlobalScopes()->where('direction', WalletTransaction::DEBIT)->count());
        $this->assertSame(30000.0, (float) $bill->fresh()->paid_amount);

        $another = Bill::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_id' => $this->unit->id, 'period' => Jalali::shiftPeriod(Jalali::currentPeriod(), -1),
            'base_amount' => 10000, 'total_amount' => 10000, 'paid_amount' => 0, 'due_date' => now()->addDays(10),
        ]);
        $this->as($this->hosseini)->postJson("/api/v1/wallet/pay/{$another->id}", [], $headers)->assertStatus(422);
        $this->as($this->hosseini)->postJson("/api/v1/wallet/pay/{$bill->id}", [], ['Idempotency-Key' => 'bad key!'])->assertStatus(422);
        // همان کلید از بدنه (کلاینتِ خودِ برنامه) هم تکرار را بی‌اثر می‌کند
        $this->as($this->hosseini)->postJson("/api/v1/wallet/pay/{$bill->id}", ['amount' => 30000, 'idempotency_key' => 'pay-key-00000001'])
            ->assertOk()->assertJsonPath('paid', 30000);
        $this->assertSame(1, WalletTransaction::withoutGlobalScopes()->where('direction', WalletTransaction::DEBIT)->count());
        $this->assertSame(0.0, (float) $another->fresh()->paid_amount);
        $this->assertSame(270000.0, app(WalletService::class)->balance($this->hosseini));
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
        $this->assertSame(50000.0, $wallet->balance($partner));
        $this->assertSame(300000.0 + 100000.0, $wallet->balance($this->hosseini));
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
        $before = $wallet->balance($this->hosseini);

        $this->assertSame(0.0, $wallet->refundBillOverpayment($bill, 70000, 'آزمونِ بازگشتِ بخشی'));

        $this->assertSame(50000.0, $wallet->balance($partner), 'تازه‌ترین پرداخت‌کننده اول و تا سقفِ سهمش.');
        $this->assertSame($before + 20000.0, $wallet->balance($this->hosseini), 'باقی‌مانده به پرداخت‌کننده‌ی قبلی.');
    }

    /**
     * مهاجرتِ یک‌کیفی (`2026_10_10_000002`) هیچ ردیفی را عوض نمی‌کند: پس از
     * برگشت و اجرای دوباره، همه‌ی ردیف‌ها بایت‌به‌بایت سرِ جایشان‌اند و مانده‌ی
     * حسابی که در دو مجتمع اعتبار دارد همان جمعِ دو جیبِ پیشین است.
     */
    public function test_the_single_wallet_migration_keeps_every_row_and_the_total(): void
    {
        [, $otherUnit] = $this->otherComplex();
        app(WalletService::class)->credit($this->hosseini, $otherUnit, 200000, WalletTransaction::SOURCE_ADJUSTMENT);
        $snapshot = fn () => DB::table('wallet_transactions')->orderBy('id')
            ->get(['id', 'complex_id', 'unit_id', 'user_id', 'direction', 'amount', 'balance_after', 'source', 'bill_id', 'payment_id'])
            ->map(fn ($row) => (array) $row)->all();
        $before = $snapshot();

        $migration = require database_path('migrations/2026_10_10_000002_wallet_single_canonical_balance.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('wallet_transactions', 'idempotency_key'));
        $migration->up();

        $this->assertSame($before, $snapshot());
        $this->assertSame(500000.0, app(WalletService::class)->balance($this->hosseini));
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    private function joinUnit(string $phone): User
    {
        $user = User::factory()->create(['complex_id' => null, 'role' => UserRole::Owner, 'is_active' => true, 'phone' => $phone]);
        [$invitation] = app(Membership::class)->invite($this->complex, $phone, UserRole::Tenant, $this->unit, $this->manager);
        app(Membership::class)->accept($invitation, $user);

        return $user->fresh();
    }

    /** @return array{0: Complex, 1: Unit} */
    private function otherComplex(): array
    {
        $other = Complex::factory()->create(['name' => 'مجتمع ب']);

        return [$other, Unit::factory()->create(['complex_id' => $other->id, 'unit_number' => '1'])];
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
