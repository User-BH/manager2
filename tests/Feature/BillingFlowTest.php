<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Enums\ChargeRuleType;
use App\Enums\ExpenseCategory;
use App\Enums\OccupancyStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\ChargeRule;
use App\Models\Complex;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Unit;
use App\Services\Charge\BillGenerator;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingFlowTest extends TestCase
{
    use RefreshDatabase;

    private function makeComplex(): Complex
    {
        return Complex::create([
            'name' => 'تست', 'currency' => 'toman', 'charge_due_day' => 10,
            'penalty_enabled' => false, 'payment_gateway' => 'fake',
        ]);
    }

    private function makeUnit(Complex $c, string $no, int $persons, float $area): Unit
    {
        return Unit::withoutGlobalScopes()->create([
            'complex_id' => $c->id, 'unit_number' => $no, 'floor' => 1,
            'area' => $area, 'residents_count' => $persons, 'coefficient' => 1,
            'occupancy_status' => OccupancyStatus::Rented, 'uses_elevator' => true,
        ]);
    }

    public function test_generates_bills_split_into_owner_and_tenant(): void
    {
        $c = $this->makeComplex();
        $this->makeUnit($c, '1', 2, 100);
        $this->makeUnit($c, '2', 3, 100);

        ChargeRule::withoutGlobalScopes()->create([
            'complex_id' => $c->id, 'name' => 'شارژ ثابت', 'type' => ChargeRuleType::Fixed,
            'category' => ExpenseCategory::Tenant, 'config' => ['amount' => 200000], 'is_active' => true,
        ]);
        ChargeRule::withoutGlobalScopes()->create([
            'complex_id' => $c->id, 'name' => 'اندوخته', 'type' => ChargeRuleType::Fixed,
            'category' => ExpenseCategory::Owner, 'config' => ['amount' => 500000], 'is_active' => true,
        ]);

        // A utility expense split across persons (total 1,000,000 over 5 persons).
        Expense::withoutGlobalScopes()->create([
            'complex_id' => $c->id, 'title' => 'آب', 'amount' => 1000000,
            'category' => ExpenseCategory::Tenant, 'period' => '1404-03',
            'split_method' => ChargeRuleType::UtilityByPerson, 'is_distributed' => true,
        ]);

        app(BillGenerator::class)->generate($c, '1404-03');

        $this->assertSame(2, Bill::withoutGlobalScopes()->count());

        $bill1 = Bill::withoutGlobalScopes()->where('unit_id', 1)->first();
        $this->assertEquals(500000, $bill1->owner_amount);
        // tenant: 200000 fixed + 400000 water (2 of 5 persons) = 600000
        $this->assertEquals(600000, $bill1->tenant_amount);
        $this->assertEquals(1100000, $bill1->total_amount);

        // Pool conservation: water split totals exactly 1,000,000.
        $this->assertEquals(1000000, Bill::withoutGlobalScopes()->sum('tenant_amount') - 400000);
    }

    public function test_payment_settles_bill_and_updates_unit_balance(): void
    {
        $c = $this->makeComplex();
        $this->makeUnit($c, '1', 1, 80);
        ChargeRule::withoutGlobalScopes()->create([
            'complex_id' => $c->id, 'name' => 'شارژ', 'type' => ChargeRuleType::Fixed,
            'category' => ExpenseCategory::Tenant, 'config' => ['amount' => 300000], 'is_active' => true,
        ]);

        app(BillGenerator::class)->generate($c, '1404-03');
        $bill = Bill::withoutGlobalScopes()->first();
        $this->assertSame(BillStatus::Unpaid, $bill->status);

        $payment = Payment::withoutGlobalScopes()->create([
            'complex_id' => $c->id, 'unit_id' => $bill->unit_id, 'bill_id' => $bill->id,
            'amount' => 300000, 'method' => PaymentMethod::Cash, 'status' => PaymentStatus::Pending,
        ]);

        app(PaymentService::class)->settle($payment);

        $bill->refresh();
        $this->assertSame(BillStatus::Paid, $bill->status);
        $this->assertEquals(0, Unit::withoutGlobalScopes()->find($bill->unit_id)->balance);
    }

    public function test_per_unit_discount_is_deducted_from_the_bill(): void
    {
        $c = $this->makeComplex();
        $unit = $this->makeUnit($c, '1', 1, 80);
        ChargeRule::withoutGlobalScopes()->create([
            'complex_id' => $c->id, 'name' => 'شارژ', 'type' => ChargeRuleType::Fixed,
            'category' => ExpenseCategory::Tenant, 'config' => ['amount' => 500000], 'is_active' => true,
        ]);

        \App\Models\Discount::withoutGlobalScopes()->create([
            'complex_id' => $c->id, 'unit_id' => $unit->id, 'period' => '1404-03',
            'amount' => 120000, 'reason' => 'تخفیف تست',
        ]);

        app(BillGenerator::class)->generate($c, '1404-03');

        $bill = Bill::withoutGlobalScopes()->where('unit_id', $unit->id)->first();
        $this->assertEquals(500000, $bill->base_amount);
        $this->assertEquals(120000, $bill->discount_amount);
        $this->assertEquals(380000, $bill->total_amount);
    }
}
