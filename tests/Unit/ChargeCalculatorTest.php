<?php

namespace Tests\Unit;

use App\Enums\ChargeRuleType;
use App\Enums\ExpenseCategory;
use App\Models\Unit;
use App\Services\Charge\ChargeComponent;
use App\Services\Charge\ChargeCalculator;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class ChargeCalculatorTest extends TestCase
{
    private function units(): Collection
    {
        return collect([
            (new Unit)->forceFill(['id' => 1, 'is_active' => true, 'floor' => 0, 'area' => 80, 'residents_count' => 2, 'coefficient' => 1, 'uses_elevator' => true]),
            (new Unit)->forceFill(['id' => 2, 'is_active' => true, 'floor' => 1, 'area' => 100, 'residents_count' => 3, 'coefficient' => 1.5, 'uses_elevator' => true]),
            (new Unit)->forceFill(['id' => 3, 'is_active' => true, 'floor' => 2, 'area' => 120, 'residents_count' => 5, 'coefficient' => 2, 'uses_elevator' => true]),
        ]);
    }

    private function component(ChargeRuleType $type, array $opts = []): ChargeComponent
    {
        return new ChargeComponent(
            label: $opts['label'] ?? 'تست',
            type: $type,
            category: $opts['category'] ?? ExpenseCategory::Tenant,
            config: $opts['config'] ?? [],
            poolAmount: $opts['pool'] ?? null,
            targetUnitIds: $opts['targets'] ?? null,
        );
    }

    public function test_fixed_charges_every_unit_the_same(): void
    {
        $result = (new ChargeCalculator)->calculate(
            $this->units(),
            [$this->component(ChargeRuleType::Fixed, ['config' => ['amount' => 500000]])],
        );

        $this->assertSame(500000.0, $result[1]['tenant']);
        $this->assertSame(500000.0, $result[2]['tenant']);
        $this->assertSame(500000.0, $result[3]['tenant']);
    }

    public function test_per_person_scales_with_residents(): void
    {
        $result = (new ChargeCalculator)->calculate(
            $this->units(),
            [$this->component(ChargeRuleType::PerPerson, ['config' => ['amount' => 100000]])],
        );

        $this->assertSame(200000.0, $result[1]['tenant']); // 2 persons
        $this->assertSame(300000.0, $result[2]['tenant']); // 3 persons
        $this->assertSame(500000.0, $result[3]['tenant']); // 5 persons
    }

    public function test_per_area_scales_with_area(): void
    {
        $result = (new ChargeCalculator)->calculate(
            $this->units(),
            [$this->component(ChargeRuleType::PerArea, ['config' => ['amount' => 5000]])],
        );

        $this->assertSame(400000.0, $result[1]['tenant']); // 80 * 5000
        $this->assertSame(500000.0, $result[2]['tenant']); // 100 * 5000
        $this->assertSame(600000.0, $result[3]['tenant']); // 120 * 5000
    }

    public function test_combined_adds_base_area_and_person_parts(): void
    {
        $result = (new ChargeCalculator)->calculate(
            $this->units(),
            [$this->component(ChargeRuleType::Combined, ['config' => [
                'base' => 200000, 'per_area_rate' => 1000, 'per_person_rate' => 50000,
            ]])],
        );

        // unit 1: 200000 + 80*1000 + 2*50000 = 380000
        $this->assertSame(380000.0, $result[1]['tenant']);
        // unit 3: 200000 + 120*1000 + 5*50000 = 570000
        $this->assertSame(570000.0, $result[3]['tenant']);
    }

    public function test_utility_pool_splits_by_persons_and_sums_to_pool(): void
    {
        $result = (new ChargeCalculator)->calculate(
            $this->units(),
            [$this->component(ChargeRuleType::UtilityByPerson, ['pool' => 1000000])],
        );

        // 10 persons total -> 100000 per person
        $this->assertSame(200000.0, $result[1]['tenant']);
        $this->assertSame(300000.0, $result[2]['tenant']);
        $this->assertSame(500000.0, $result[3]['tenant']);
        $this->assertEqualsWithDelta(1000000.0, $this->sum($result), 0.001);
    }

    public function test_by_unit_count_splits_equally_and_conserves_pool(): void
    {
        // 1,000,000 / 3 does not divide evenly; largest-remainder must still total the pool.
        $result = (new ChargeCalculator)->calculate(
            $this->units(),
            [$this->component(ChargeRuleType::ByUnitCount, ['pool' => 1000000])],
        );

        $this->assertEqualsWithDelta(1000000.0, $this->sum($result), 0.001);
        foreach ([1, 2, 3] as $id) {
            $this->assertContains($result[$id]['tenant'], [333333.0, 333334.0]);
        }
    }

    public function test_by_coefficient_weights_shares(): void
    {
        // coefficients 1 : 1.5 : 2  => total 4.5
        $result = (new ChargeCalculator)->calculate(
            $this->units(),
            [$this->component(ChargeRuleType::ByCoefficient, ['pool' => 4500000])],
        );

        $this->assertEqualsWithDelta(1000000.0, $result[1]['tenant'], 1);
        $this->assertEqualsWithDelta(1500000.0, $result[2]['tenant'], 1);
        $this->assertEqualsWithDelta(2000000.0, $result[3]['tenant'], 1);
        $this->assertEqualsWithDelta(4500000.0, $this->sum($result), 0.001);
    }

    public function test_elevator_exempts_ground_floor_and_weights_by_floor(): void
    {
        // floors: u1=0 (exempt), u2=1, u3=2 -> weights 0,1,2 total 3
        $result = (new ChargeCalculator)->calculate(
            $this->units(),
            [$this->component(ChargeRuleType::ElevatorByFloor, ['pool' => 900000, 'config' => ['exempt_ground_floor' => true]])],
        );

        $this->assertSame(0.0, $result[1]['tenant']);
        $this->assertEqualsWithDelta(300000.0, $result[2]['tenant'], 1);
        $this->assertEqualsWithDelta(600000.0, $result[3]['tenant'], 1);
        $this->assertEqualsWithDelta(900000.0, $this->sum($result), 0.001);
    }

    public function test_target_units_limits_scope(): void
    {
        $result = (new ChargeCalculator)->calculate(
            $this->units(),
            [$this->component(ChargeRuleType::Fixed, ['config' => ['amount' => 100000], 'targets' => [2]])],
        );

        $this->assertSame(0.0, $result[1]['tenant']);
        $this->assertSame(100000.0, $result[2]['tenant']);
        $this->assertSame(0.0, $result[3]['tenant']);
    }

    public function test_owner_and_tenant_categories_are_separated(): void
    {
        $result = (new ChargeCalculator)->calculate(
            $this->units(),
            [
                $this->component(ChargeRuleType::Fixed, ['config' => ['amount' => 300000], 'category' => ExpenseCategory::Tenant]),
                $this->component(ChargeRuleType::Fixed, ['config' => ['amount' => 1000000], 'category' => ExpenseCategory::Owner]),
            ],
        );

        $this->assertSame(300000.0, $result[1]['tenant']);
        $this->assertSame(1000000.0, $result[1]['owner']);
        $this->assertSame(1300000.0, $result[1]['base']);
    }

    private function sum(array $result): float
    {
        return array_sum(array_map(fn ($r) => $r['base'], $result));
    }
}
