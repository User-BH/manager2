<?php

namespace App\Models;

use App\Models\Concerns\BelongsToComplex;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Building extends Model
{
    use BelongsToComplex;

    protected $fillable = ['complex_id', 'name', 'floors_count', 'description'];

    /** @return HasMany<Unit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }
}
