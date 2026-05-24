<?php

namespace App\Models;

use App\Enums\ChargeRuleType;
use App\Enums\ExpenseCategory;
use App\Models\Concerns\BelongsToComplex;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use BelongsToComplex;

    protected $fillable = [
        'complex_id', 'title', 'amount', 'category', 'period', 'spend_date',
        'vendor', 'split_method', 'split_config', 'target_units',
        'is_distributed', 'description', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'category' => ExpenseCategory::class,
            'split_method' => ChargeRuleType::class,
            'split_config' => 'array',
            'target_units' => 'array',
            'is_distributed' => 'boolean',
            'spend_date' => 'date',
        ];
    }
}
