<?php

namespace App\Models;

use App\Enums\OccupancyStatus;
use App\Enums\ResidentRelation;
use App\Models\Concerns\BelongsToComplex;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    use BelongsToComplex;

    protected $fillable = [
        'complex_id', 'building_id', 'unit_number', 'floor', 'area',
        'residents_count', 'parking_count', 'occupancy_status',
        'coefficient', 'uses_elevator', 'balance', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'area' => 'decimal:2',
            'coefficient' => 'decimal:4',
            'balance' => 'decimal:2',
            'uses_elevator' => 'boolean',
            'is_active' => 'boolean',
            'occupancy_status' => OccupancyStatus::class,
        ];
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function residents(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['relation', 'share_percent', 'start_date', 'end_date', 'is_current'])
            ->withTimestamps();
    }

    public function owners(): BelongsToMany
    {
        return $this->residents()->wherePivot('relation', ResidentRelation::Owner->value)->wherePivot('is_current', true);
    }

    public function tenants(): BelongsToMany
    {
        return $this->residents()->wherePivot('relation', ResidentRelation::Tenant->value)->wherePivot('is_current', true);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function label(): string
    {
        return 'واحد '.$this->unit_number.' - طبقه '.$this->floor;
    }

    /** Recompute the cached debt balance from outstanding bills. */
    public function recalculateBalance(): void
    {
        $this->balance = $this->bills()->sum('total_amount') - $this->bills()->sum('paid_amount');
        $this->saveQuietly();
    }
}
