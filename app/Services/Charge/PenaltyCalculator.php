<?php

namespace App\Services\Charge;

use App\Models\Complex;

/**
 * Late-payment penalty. Kept pure (no DB) so it is trivial to unit test;
 * the caller supplies the overdue amount and how many days it is late.
 */
class PenaltyCalculator
{
    public function compute(float $overdueAmount, int $daysLate, Complex $complex): float
    {
        if (! $complex->penalty_enabled || $overdueAmount <= 0) {
            return 0.0;
        }

        $effectiveDays = $daysLate - (int) $complex->penalty_grace_days;
        if ($effectiveDays <= 0) {
            return 0.0;
        }

        $value = (float) $complex->penalty_value;

        $penalty = match ($complex->penalty_type) {
            'fixed' => $value,
            'percent' => $overdueAmount * $value / 100,
            'percent_per_day' => $overdueAmount * $value / 100 * $effectiveDays,
            default => 0.0,
        };

        return round($penalty);
    }
}
