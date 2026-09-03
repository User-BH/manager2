<?php

namespace App\Models;

use App\Enums\ChargeRuleType;
use App\Enums\ExpenseCategory;
use App\Models\Concerns\BelongsToComplex;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    /*
     * ⚠️ `SoftDeletes` و نه بستنِ کاملِ حذف (R51).
     *
     * برخلافِ قبض و پرداخت، حذفِ هزینه یک عملیاتِ **مشروع** است: مدیر
     * مبلغی را اشتباه وارد کرده و می‌خواهد پاکش کند. آنچه نباید ممکن
     * باشد، برگشت‌ناپذیر بودنش است.
     *
     * پیش از این `$expense->delete()` رکورد را برای همیشه می‌برد؛
     * سیاهه‌ی ممیزی می‌گفت چه کسی حذف کرده، ولی خودِ مبلغ و شرح رفته
     * بود و فقط با بازیابیِ کلِ بکاپ برمی‌گشت.
     */
    use BelongsToComplex, SoftDeletes;
    use HasFactory;

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
