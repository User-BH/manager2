<?php

namespace App\Services\Charge;

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\Complex;
use App\Support\Jalali;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates a monthly charge run for one complex and period:
 *   standing charge rules + distributable expenses  ->  per-unit bills.
 * Idempotent per (unit, period): re-running updates existing unpaid bills.
 */
class BillGenerator
{
    public function __construct(
        protected ChargeCalculator $calculator,
        protected PenaltyCalculator $penalty,
    ) {}

    /**
     * @param  array<int,float>  $discounts  map of unit_id => discount amount
     * @return array{bills:int,total:float}
     */
    public function generate(Complex $complex, string $period, array $discounts = []): array
    {
        $units = $complex->units()->where('is_active', true)->get();

        // Build the component list: standing rules first, then period expenses.
        $components = [];
        foreach ($complex->chargeRules()->where('is_active', true)->orderBy('sort_order')->get() as $rule) {
            $components[] = ChargeComponent::fromRule($rule);
        }
        foreach ($complex->expenses()->where('period', $period)->where('is_distributed', true)->whereNotNull('split_method')->get() as $expense) {
            $components[] = ChargeComponent::fromExpense($expense);
        }

        $breakdownByUnit = $this->calculator->calculate($units, $components);

        $dueDate = Jalali::dueDate($period, (int) $complex->charge_due_day);
        $count = 0;
        $grandTotal = 0.0;

        DB::transaction(function () use ($units, $complex, $period, $breakdownByUnit, $discounts, $dueDate, &$count, &$grandTotal) {
            foreach ($units as $unit) {
                $calc = $breakdownByUnit[$unit->id] ?? ['owner' => 0, 'tenant' => 0, 'base' => 0, 'items' => []];

                $discount = (float) ($discounts[$unit->id] ?? 0);
                $penalty = $this->penaltyForUnit($unit->id, $complex, $period, $dueDate);

                $base = (float) $calc['base'];
                $total = max(0, $base + $penalty - $discount);

                $items = $calc['items'];
                if ($penalty > 0) {
                    $items[] = ['label' => 'جریمه دیرکرد', 'category' => 'tenant', 'amount' => $penalty];
                }
                if ($discount > 0) {
                    $items[] = ['label' => 'تخفیف', 'category' => 'tenant', 'amount' => -$discount];
                }

                $bill = Bill::withoutGlobalScopes()->firstOrNew([
                    'unit_id' => $unit->id,
                    'period' => $period,
                ]);

                // Never overwrite a settled bill on re-run.
                if ($bill->exists && $bill->status === BillStatus::Paid) {
                    continue;
                }

                $bill->complex_id = $complex->id;
                $bill->owner_amount = (float) $calc['owner'];
                $bill->tenant_amount = (float) $calc['tenant'];
                $bill->base_amount = $base;
                $bill->penalty_amount = $penalty;
                $bill->discount_amount = $discount;
                $bill->total_amount = $total;
                $bill->due_date = $dueDate;
                $bill->issued_at = now();
                $bill->breakdown = $items;
                $bill->save();
                $bill->syncStatus();

                $count++;
                $grandTotal += $total;
            }
        });

        return ['bills' => $count, 'total' => $grandTotal];
    }

    /** Penalty on this unit's still-unpaid bills from earlier periods. */
    protected function penaltyForUnit(int $unitId, Complex $complex, string $period, Carbon $asOf): float
    {
        if (! $complex->penalty_enabled) {
            return 0.0;
        }

        $overdue = Bill::withoutGlobalScopes()
            ->where('unit_id', $unitId)
            ->where('period', '!=', $period)
            ->whereIn('status', [BillStatus::Unpaid, BillStatus::Partial])
            ->whereNotNull('due_date')
            ->where('due_date', '<', $asOf)
            ->get();

        $penalty = 0.0;
        foreach ($overdue as $bill) {
            $remaining = $bill->remaining();
            $daysLate = (int) $bill->due_date->diffInDays($asOf);
            $penalty += $this->penalty->compute($remaining, $daysLate, $complex);
        }

        return round($penalty);
    }
}
