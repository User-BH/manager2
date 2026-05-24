<?php

namespace App\Models;

use App\Enums\BillStatus;
use App\Models\Concerns\BelongsToComplex;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bill extends Model
{
    use BelongsToComplex;

    protected $fillable = [
        'complex_id', 'unit_id', 'period', 'owner_amount', 'tenant_amount',
        'base_amount', 'penalty_amount', 'discount_amount', 'total_amount',
        'paid_amount', 'status', 'due_date', 'paid_at', 'issued_at', 'breakdown',
    ];

    protected function casts(): array
    {
        return [
            'owner_amount' => 'decimal:2',
            'tenant_amount' => 'decimal:2',
            'base_amount' => 'decimal:2',
            'penalty_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'status' => BillStatus::class,
            'due_date' => 'date',
            'paid_at' => 'date',
            'issued_at' => 'datetime',
            'breakdown' => 'array',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function remaining(): float
    {
        return max(0, (float) $this->total_amount - (float) $this->paid_amount);
    }

    /** Re-derive status from paid_amount and refresh the unit balance. */
    public function syncStatus(): void
    {
        $remaining = $this->remaining();

        if ($remaining <= 0 && (float) $this->total_amount > 0) {
            $this->status = BillStatus::Paid;
            $this->paid_at ??= now();
        } elseif ((float) $this->paid_amount > 0) {
            $this->status = BillStatus::Partial;
        } else {
            $this->status = BillStatus::Unpaid;
        }

        $this->save();
        $this->unit->recalculateBalance();
    }
}
