<?php

namespace App\Models;

use App\Models\Concerns\BelongsToComplex;
use Illuminate\Database\Eloquent\Model;

class Income extends Model
{
    use BelongsToComplex;

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
