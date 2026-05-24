<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToComplex;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use BelongsToComplex;

    protected $fillable = [
        'complex_id', 'unit_id', 'bill_id', 'user_id', 'amount', 'method',
        'status', 'period', 'gateway', 'ref_id', 'tracking_code', 'paid_at',
        'receipt_path', 'receipt_original_name', 'receipt_paid_on',
        'reviewed_by', 'reviewed_at', 'review_note', 'description',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'paid_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'receipt_paid_on' => 'date',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
