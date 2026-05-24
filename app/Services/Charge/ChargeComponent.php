<?php

namespace App\Services\Charge;

use App\Enums\ChargeRuleType;
use App\Enums\ExpenseCategory;
use App\Models\ChargeRule;
use App\Models\Expense;

/**
 * A single billable component fed into the charge calculator.
 * Both standing charge rules and distributable expenses are normalised
 * into this shape so the calculator has one uniform input.
 */
class ChargeComponent
{
    public function __construct(
        public string $label,
        public ChargeRuleType $type,
        public ExpenseCategory $category,
        public array $config = [],
        public ?float $poolAmount = null,
        public ?array $targetUnitIds = null,
    ) {}

    public static function fromRule(ChargeRule $rule): self
    {
        return new self(
            label: $rule->name,
            type: $rule->type,
            category: $rule->category,
            config: $rule->config ?? [],
            poolAmount: $rule->pool_amount !== null ? (float) $rule->pool_amount : null,
            targetUnitIds: $rule->target_units,
        );
    }

    public static function fromExpense(Expense $expense): self
    {
        return new self(
            label: $expense->title,
            type: $expense->split_method,
            category: $expense->category,
            config: $expense->split_config ?? [],
            poolAmount: (float) $expense->amount,
            targetUnitIds: $expense->target_units,
        );
    }
}
