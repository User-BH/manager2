<?php

namespace Tests\Feature;

use App\Enums\ChargeRuleType;
use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\ChargeRule;
use App\Models\Complex;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\UnitTenure;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Charge\BillGenerator;
use App\Services\Finance\FinancialInvariants;
use App\Services\Payment\PaymentService;
use App\Services\Units\TenureService;
use App\Services\Wallet\WalletService;
use App\Support\Jalali;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * منبعِ حقیقتِ مالی و کش‌هایش — مرحلهٔ ۱۰ · فاز ۱۳ (بندهای ۲ و ۱۰۵ ِ فایلِ معماری).
 *
 * بدهی را قبض تعریف می‌کند و پرداخت را دفتر (تخصیصِ پرداخت + برداشتِ کیف − بازگشت از کاهشِ قبض)؛ `units.balance` فقط
 * کشِ آن است. هر مسیرِ پولی ناوردایی را نگه می‌دارد، هیچ مسیرِ عادی کش را مستقیم نمی‌نویسد، و `finance:check` ناهمخوانی
 * را پیدا می‌کند — کش را ترمیم می‌کند ولی دفتر را هرگز.
 */
class FinancialInvariantTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $manager;

    private Unit $unit;

    private User $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create([
            'name' => 'مجتمعِ دفتر', 'charge_due_day' => 10, 'penalty_enabled' => false, 'payment_gateway' => 'fake',
        ]);
        $this->manager = User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true,
        ]);
        $this->unit = Unit::factory()->create(['complex_id' => $this->complex->id, 'unit_number' => '7', 'area' => 120]);
        $this->tenant = User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => UserRole::Tenant, 'is_active' => true, 'phone' => '09127770001',
        ]);
        app(TenureService::class)->open($this->unit, $this->tenant, ResidentRelation::Tenant, 100, now()->subMonths(6));
    }

    /**
     * 🔴 بندِ ۱۰۵: هر مسیرِ پولی — صدور، پرداختِ جزئی، پرداخت از کیف، پرداختِ بیش از بدهی، کاهشِ قبض پس از پرداخت —
     * کش را با قبض‌ها و پرداخت‌شده را با دفتر هم‌خوان می‌گذارد.
     */
    public function test_every_money_path_keeps_the_cache_and_the_ledger_in_step(): void
    {
        $period = Jalali::currentPeriod();
        $rule = ChargeRule::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'name' => 'شارژ ثابت', 'type' => ChargeRuleType::Fixed,
            'category' => ExpenseCategory::Tenant, 'config' => ['amount' => 300000], 'is_active' => true,
        ]);

        app(BillGenerator::class)->generate($this->complex, $period);
        $bill = Bill::withoutGlobalScopes()->where('unit_id', $this->unit->id)->firstOrFail();
        $this->assertConsistent('صدور', 300000);

        $this->settle($bill, 100000);
        $this->assertConsistent('پرداختِ جزئی از درگاه', 200000);

        app(WalletService::class)->credit($this->tenant, $this->unit, 50000, WalletTransaction::SOURCE_ADJUSTMENT);
        app(WalletService::class)->payBill($this->tenant, $bill->fresh(), 50000);
        $this->assertConsistent('پرداخت از کیف', 150000);

        $this->settle($bill, 400000);
        $this->assertConsistent('پرداختِ بیش از بدهی (مازاد به کیف)', 0);

        // کاهشِ قبضِ پرداخت‌شده: مازاد به پرداخت‌کننده برمی‌گردد و پرداخت‌شده پایین می‌آید
        $bill->forceFill(['status' => 'partial'])->save();
        $rule->update(['config' => ['amount' => 120000]]);
        app(BillGenerator::class)->generate($this->complex, $period);
        $this->assertSame(120000.0, (float) $bill->fresh()->paid_amount);
        $this->assertConsistent('کاهشِ قبض پس از پرداخت', 0);
    }

    /** 🔴 هیچ مسیرِ عادی مانده‌ی واحد را بی قبض عوض نمی‌کند: نه ذخیره‌ی مستقیم، نه پرشدنِ گروهی، نه فرمِ مدیر. */
    public function test_no_normal_path_writes_the_balance_without_a_bill(): void
    {
        $this->bill(250000);

        try {
            $this->unit->fresh()->forceFill(['balance' => 0])->save();
            $this->fail('ذخیره‌ی مستقیمِ مانده پذیرفته شد.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('recalculateBalance', $e->getMessage());
        }

        $this->unit->fresh()->update(['balance' => 0, 'notes' => 'پرشدنِ گروهی']);
        $this->as($this->manager)->putJson("/api/v1/units/{$this->unit->id}", [
            'unit_number' => '7', 'floor' => 1, 'area' => 120, 'residents_count' => 2, 'coefficient' => 1,
            'occupancy_status' => 'rented', 'uses_elevator' => true, 'balance' => 0,
        ]);

        $this->assertSame(250000.0, (float) $this->unit->fresh()->balance);
        $this->assertSame([], app(FinancialInvariants::class)->violations());
    }

    /**
     * 🔴 در کدِ برنامه فقط `Unit::recalculateBalanceFor` ستونِ `units.balance` را می‌نویسد — نه `increment`، نه
     * `update([... 'balance' ...])` روی واحد.
     */
    public function test_only_the_recalculation_writes_the_unit_balance_column(): void
    {
        $writers = [];
        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            $source = $file->getContents();
            $writes = '/(increment|decrement)\(\s*[\'"]balance[\'"]'
                .'|units\s+SET\s+balance'
                .'|->balance\s*=(?![=>])'
                .'|table\(\s*[\'"]units[\'"]\s*\)[^;]*->update\([^;]*[\'"]balance[\'"]/is';
            if (preg_match($writes, $source)) {
                $writers[] = str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame(['Models'.DIRECTORY_SEPARATOR.'Unit.php'], $writers, 'نوشتنِ مستقیمِ مانده‌ی واحد بیرون از Unit.');
    }

    /** بازشماری یک دستور است — جمع و نوشتن جدا نیستند تا پرداختِ هم‌زمان جمعِ کهنه را آخر بنویسد. */
    public function test_recalculation_is_a_single_statement(): void
    {
        $bill = $this->bill(90000);
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*update\s+["`]?units/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $bill->paid_amount = 40000;
        $bill->save();

        $this->assertCount(1, $writes);
        $this->assertMatchesRegularExpression('/SET balance = \(SELECT COALESCE\(SUM/i', $writes[0]);
        $this->assertSame(50000.0, (float) $this->unit->fresh()->balance);
    }

    /** 🔴 `finance:check` ناهمخوانی را می‌گوید؛ `--repair` کش را از قبض‌ها می‌سازد ولی قبضِ ناهمخوان با دفتر را دست نمی‌زند. */
    public function test_the_checker_reports_drift_and_repairs_only_the_cache(): void
    {
        $bill = $this->bill(300000);
        $this->settle($bill, 100000);
        $this->artisan('finance:check')->assertSuccessful();

        DB::table('units')->where('id', $this->unit->id)->update(['balance' => 999]);
        $this->artisan('finance:check')
            ->expectsOutputToContain("مانده‌ی واحد #{$this->unit->id}: کش 999")
            ->assertFailed();
        $this->artisan('finance:check --repair')->assertSuccessful();
        $this->assertSame(200000.0, (float) $this->unit->fresh()->balance);

        DB::table('bills')->where('id', $bill->id)->update(['paid_amount' => 150000]);
        $this->artisan('finance:check --repair')
            ->expectsOutputToContain("قبض #{$bill->id}: پرداخت‌شده 150000")
            ->assertFailed();
        $this->assertSame(150000.0, (float) DB::table('bills')->where('id', $bill->id)->value('paid_amount'), 'ترمیم دفتر را «درست» کرد.');
    }

    /**
     * 🔴 بندِ ۲: «بدهیِ من» یک عدد است — داشبوردِ واحد، «قبض‌های من» و پروفایل همان قبض‌هایی را می‌شمارند که فهرست
     * نشان می‌دهد. مستأجرِ تازه بدهیِ ساکنِ قبلی را نمی‌بیند و ساکنِ قبلی (که به واحدِ دیگرِ همان مجتمع رفته) بدهیِ
     * ساکنِ بعدی را؛ بدهیِ **واحد** و مجتمع در نمای مدیر هر دو را دارد.
     */
    public function test_every_debt_figure_reads_the_same_bills(): void
    {
        $old = $this->bill(400000, Jalali::shiftPeriod(Jalali::currentPeriod(), -2));

        // ساکنِ قبلی ماهِ پیش به واحدِ ۸ ِ همان مجتمع رفت؛ ساکنِ تازه از امروز در واحدِ ۷
        $tenures = app(TenureService::class);
        $tenures->close(UnitTenure::query()->where('user_id', $this->tenant->id)->where('unit_id', $this->unit->id)->firstOrFail(), now()->subMonth());
        $eight = Unit::factory()->create(['complex_id' => $this->complex->id, 'unit_number' => '8']);
        $tenures->open($eight, $this->tenant, ResidentRelation::Tenant, 100, now()->subMonth());
        $next = User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => UserRole::Tenant, 'is_active' => true, 'phone' => '09127770002',
        ]);
        $tenures->open($this->unit, $next, ResidentRelation::Tenant, 100, now());
        $current = $this->bill(150000);
        $this->settle($current, 50000);

        $next = $next->fresh();
        $this->as($next)->getJson('/api/v1/my-bills')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('totalDebt', 100000);
        $this->as($next)->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('totalDebt', 100000)->assertJsonPath('units.0.balance', 100000);
        $profile = $this->as($next)->getJson('/api/v1/profile')->assertOk();
        $this->assertSame(100000, $profile->json('stats.totalDebt'));
        $this->assertSame([100000], array_column($profile->json('units'), 'balance'));

        // ساکنِ قبلی: روی واحدِ ۷ فقط بدهیِ دوره‌های خودش (پیش از این ۵۰۰٬۰۰۰ = مانده‌ی امروزِ واحد با بدهیِ ساکنِ بعدی)
        $former = $this->as($this->tenant->fresh())->getJson('/api/v1/profile')->assertOk();
        $byUnit = array_column($former->json('units'), 'balance', 'id');
        $this->assertSame(400000, $byUnit[$this->unit->id] ?? null, json_encode($former->json('units')));
        $this->assertSame(0, $byUnit[$eight->id] ?? null);
        $this->assertSame(0, $former->json('stats.totalDebt'));

        // نمای مدیر: بدهیِ واحد و مجتمع هر دو قبض را دارد، و هر سه عدد یکی‌اند
        $manager = $this->as($this->manager)->getJson('/api/v1/dashboard')->assertOk();
        $this->assertSame(500000, $manager->json('totalDebt'));
        $this->assertSame([500000], array_column($manager->json('debtors'), 'balance'));
        $this->assertSame(500000.0, (float) $this->unit->fresh()->balance);
        $this->assertSame([], app(FinancialInvariants::class)->violations());
        $this->assertSame(400000.0, (float) $old->fresh()->total_amount - (float) $old->fresh()->paid_amount);
    }

    /**
     * بندِ ۲، مثالِ خودِ فایل: مساحتی که مدیر ثبت می‌کند همان است که مدیر، ساکن (پروفایل و «اطلاعاتِ واحد») می‌بینند —
     * یک ستون، نه چند نسخه. و موجودیِ صندوق فقط از دفتر حساب می‌شود؛ ستونِ مرده‌ی `fund_balance` در هیچ پاسخی نیست.
     */
    public function test_one_area_everywhere_and_no_second_fund_balance(): void
    {
        $this->as($this->manager)->putJson("/api/v1/units/{$this->unit->id}", [
            'unit_number' => '7', 'floor' => 1, 'area' => 300, 'residents_count' => 2, 'coefficient' => 1,
            'occupancy_status' => 'rented', 'uses_elevator' => true,
        ])->assertOk();

        $this->assertSame(300, (int) $this->as($this->manager)->getJson("/api/v1/units/{$this->unit->id}")->json('unit.area'));
        $this->assertSame([300], array_map('intval', array_column($this->as($this->tenant)->getJson('/api/v1/profile')->json('units'), 'area')));
        $this->assertSame(300, (int) $this->as($this->tenant)->getJson("/api/v1/units/{$this->unit->id}/information")->json('managed.area'));

        DB::table('complexes')->where('id', $this->complex->id)->update(['fund_balance' => 777777]);
        $this->assertArrayNotHasKey('fund_balance', $this->complex->fresh()->toArray());
        $this->assertNotSame(777777, $this->as($this->manager)->getJson('/api/v1/dashboard')->json('balance'));
        // فقط مدلِ مجتمع نامش را می‌برد (پنهان‌کردن)؛ هیچ کد یا رابطی آن را نمی‌خواند یا نمی‌نویسد
        foreach (Finder::create()->files()->in([app_path(), resource_path('js')])->notPath('Models/Complex.php') as $file) {
            $this->assertStringNotContainsString('fund_balance', $file->getContents(), $file->getRelativePathname());
        }
    }

    private function assertConsistent(string $step, float $debt): void
    {
        $this->assertSame([], app(FinancialInvariants::class)->violations(), "ناوردایی پس از «{$step}» شکست.");
        $this->assertSame($debt, (float) $this->unit->fresh()->balance, "مانده پس از «{$step}».");
    }

    private function bill(float $total, ?string $period = null): Bill
    {
        return Bill::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_id' => $this->unit->id, 'period' => $period ?? Jalali::currentPeriod(),
            'base_amount' => $total, 'total_amount' => $total, 'paid_amount' => 0, 'due_date' => now()->addDays(10),
        ]);
    }

    private function settle(Bill $bill, float $amount): void
    {
        $payment = Payment::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_id' => $this->unit->id, 'bill_id' => $bill->id,
            'user_id' => $this->tenant->id, 'amount' => $amount, 'method' => PaymentMethod::Online,
            'status' => PaymentStatus::Pending, 'period' => $bill->period,
        ]);
        app(PaymentService::class)->settle($payment);
    }

    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);

        return $this->actingAs($user);
    }
}
