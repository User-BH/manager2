<?php

namespace App\Services\Charge;

use App\Enums\ChargeRuleType;
use App\Models\Unit;
use Illuminate\Support\Collection;

/**
 * The financial core. Turns a set of charge components into a per-unit
 * breakdown, split into owner-side and tenant-side amounts.
 *
 * Output shape, keyed by unit id:
 *   [
 *     'owner'  => float,   // sum of owner-category items
 *     'tenant' => float,   // sum of tenant-category items
 *     'base'   => float,   // owner + tenant
 *     'items'  => [ ['label' => string, 'category' => 'owner|tenant', 'amount' => float], ... ],
 *   ]
 *
 * All amounts are rounded to whole currency units. Pool-based components use
 * the largest-remainder method so the per-unit shares sum back to the pool
 * exactly (no money created or lost to rounding).
 */
class ChargeCalculator
{
    /**
     * @param  Collection<int,Unit>  $units
     * @param  ChargeComponent[]  $components
     * @return array<int,array{owner:float,tenant:float,base:float,items:array}>
     */
    public function calculate(Collection $units, array $components): array
    {
        $result = [];
        foreach ($units as $unit) {
            $result[$unit->id] = ['owner' => 0.0, 'tenant' => 0.0, 'base' => 0.0, 'items' => []];
        }

        foreach ($components as $component) {
            $applicable = $this->applicableUnits($units, $component);
            if ($applicable->isEmpty()) {
                continue;
            }

            $shares = $component->type->isPoolBased()
                ? $this->poolShares($applicable, $component)
                : $this->perUnitShares($applicable, $component);

            foreach ($shares as $unitId => $amount) {
                if ($amount <= 0) {
                    continue;
                }
                $bucket = $component->category->value; // owner | tenant
                $result[$unitId][$bucket] += $amount;
                $result[$unitId]['base'] += $amount;
                $result[$unitId]['items'][] = [
                    'label' => $component->label,
                    'category' => $bucket,
                    'amount' => $amount,
                ];
            }
        }

        return $result;
    }

    /** @return Collection<int,Unit> */
    protected function applicableUnits(Collection $units, ChargeComponent $component): Collection
    {
        $units = $units->filter(fn (Unit $u) => $u->is_active);

        if (! empty($component->targetUnitIds)) {
            $ids = array_map('intval', $component->targetUnitIds);
            $units = $units->filter(fn (Unit $u) => in_array($u->id, $ids, true));
        }

        if ($component->type === ChargeRuleType::ElevatorByFloor) {
            $units = $units->filter(fn (Unit $u) => $u->uses_elevator);
        }

        return $units->values();
    }

    /**
     * Components computed independently from each unit's own attributes.
     *
     * @return array<int,float>
     */
    protected function perUnitShares(Collection $units, ChargeComponent $c): array
    {
        $cfg = $c->config;
        $shares = [];

        foreach ($units as $unit) {
            $amount = match ($c->type) {
                ChargeRuleType::Fixed => (float) ($cfg['amount'] ?? 0),
                ChargeRuleType::PerPerson => (float) ($cfg['amount'] ?? 0) * $unit->residents_count,
                ChargeRuleType::PerArea => (float) ($cfg['amount'] ?? 0) * (float) $unit->area,
                ChargeRuleType::Combined => (float) ($cfg['base'] ?? 0)
                    + (float) ($cfg['per_area_rate'] ?? 0) * (float) $unit->area
                    + (float) ($cfg['per_person_rate'] ?? 0) * $unit->residents_count,
                default => 0.0,
            };

            $shares[$unit->id] = round($amount);
        }

        return $shares;
    }

    /**
     * Pool-based components: a single amount split across units by weight.
     *
     * @return array<int,float>
     */
    protected function poolShares(Collection $units, ChargeComponent $c): array
    {
        $pool = (float) ($c->poolAmount ?? 0);
        if ($pool <= 0) {
            return [];
        }

        $weights = [];
        foreach ($units as $unit) {
            $weights[$unit->id] = $this->weightFor($unit, $c);
        }

        $totalWeight = array_sum($weights);
        if ($totalWeight <= 0) {
            // Fall back to an equal split when no positive weights exist.
            $weights = array_fill_keys(array_keys($weights), 1.0);
            $totalWeight = (float) count($weights);
        }

        return $this->largestRemainderDistribute($pool, $weights, $totalWeight);
    }

    protected function weightFor(Unit $unit, ChargeComponent $c): float
    {
        return match ($c->type) {
            ChargeRuleType::UtilityByPerson => (float) $unit->residents_count,
            ChargeRuleType::ByUnitCount => 1.0,
            ChargeRuleType::ByCoefficient => (float) $unit->coefficient,
            ChargeRuleType::ElevatorByFloor => $this->elevatorWeight($unit, $c->config),
            default => 0.0,
        };
    }

    protected function elevatorWeight(Unit $unit, array $cfg): float
    {
        $exemptGround = (bool) ($cfg['exempt_ground_floor'] ?? true);
        $floor = (int) $unit->floor;

        if ($floor <= 0) {
            return $exemptGround ? 0.0 : 1.0;
        }

        // Higher floors carry more weight (one share per floor by default).
        return (float) $floor;
    }

    /**
     * Distribute an integer pool across units proportional to weights, rounding
     * down then handing the leftover units the largest fractional remainders.
     *
     * @param  array<int,float>  $weights
     * @return array<int,float>
     */
    protected function largestRemainderDistribute(float $pool, array $weights, float $totalWeight): array
    {
        $poolInt = (int) round($pool);
        $exact = [];
        $floors = [];
        $assigned = 0;

        foreach ($weights as $unitId => $weight) {
            $value = $poolInt * ($weight / $totalWeight);
            $exact[$unitId] = $value;
            $floors[$unitId] = (int) floor($value);
            $assigned += $floors[$unitId];
        }

        $remainder = $poolInt - $assigned;

        // Hand the remaining units one extra each, biggest fractional part first.
        $fractions = [];
        foreach ($exact as $unitId => $value) {
            $fractions[$unitId] = $value - floor($value);
        }
        arsort($fractions);

        foreach (array_keys($fractions) as $unitId) {
            if ($remainder <= 0) {
                break;
            }
            $floors[$unitId]++;
            $remainder--;
        }

        return array_map('floatval', $floors);
    }
}
