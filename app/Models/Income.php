<?php

namespace App\Models;

use App\Models\Concerns\BelongsToComplex;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Income extends Model
{
    // ⚠️ مثلِ هزینه: حذف مشروع است، برگشت‌ناپذیری نه (R51)
    use BelongsToComplex, SoftDeletes;

    protected $fillable = [
        'complex_id', 'title', 'amount', 'source', 'period',
        'received_date', 'description', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'received_date' => 'date',
        ];
    }
}
