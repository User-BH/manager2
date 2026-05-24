<?php

namespace App\Models;

use App\Enums\ChargeRuleType;
use App\Enums\ExpenseCategory;
use App\Models\Concerns\BelongsToComplex;
use Illuminate\Database\Eloquent\Model;

class ChargeRule extends Model
{
    use BelongsToComplex;

    protected $fillable = [
        'complex_id', 'name', 'type', 'category', 'config',
        'pool_amount', 'target_units', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'type' => ChargeRuleType::class,
            'category' => ExpenseCategory::class,
            'config' => 'array',
            'target_units' => 'array',
            'pool_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
