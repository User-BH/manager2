<?php

namespace App\Models;

use App\Enums\OccupancyStatus;
use App\Enums\ResidentRelation;
use App\Models\Concerns\BelongsToComplex;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * واحدِ مسکونی.
 *
 * ─── چرا حذفِ نرم (R14) ────────────────────────────────────────────────────
 * `bills.unit_id` و `payments.unit_id` روی `cascadeOnDelete` هستند، پس حذفِ
 * سختِ یک واحد **کلِ تاریخچه‌ی مالی‌اش را پاک می‌کرد**. با `SoftDeletes` حذف
 * هرگز به دیتابیس نمی‌رسد، cascade شلیک نمی‌شود، و قبض‌ها و پرداخت‌ها برای
 * حسابداری می‌مانند — در حالی که واحد از دیدِ کاربر حذف شده است.
 *
 * توجه: شماره‌ی واحدِ حذف‌شده رزرو می‌ماند (قیدِ یکتاییِ
 * `complex_id, unit_number` دست‌نخورده است). حذفِ اشتباهی را با `restore()`
 * برگردانید، نه با ساختِ دوباره.
 */
class Unit extends Model
{
    use BelongsToComplex, HasFactory, SoftDeletes;

    protected $fillable = [
        'complex_id', 'building_id', 'unit_number', 'floor', 'area',
        'residents_count', 'parking_count', 'storage_count', 'occupancy_status',
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

    /** @return BelongsTo<Building, $this> */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /** @return BelongsToMany<User, $this> */
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

    /**
     * همه‌ی دوره‌های مالکیت و سکونت — جاری و پایان‌یافته (R26).
     *
     * ─── چرا کنارِ `residents()` ────────────────────────────────────────────
     * `residents()` یک `belongsToMany` است و ذاتاً «چه کسانی الان اینجا
     * هستند» را جواب می‌دهد. تاریخچه پرسشِ دیگری است («چه کسی از کِی تا
     * کِی») و با همان رابطه هم خوانا نمی‌شود و هم وسوسه‌ی `sync` را زنده
     * نگه می‌دارد — همان چیزی که سابقه را پاک می‌کرد.
     *
     * @return HasMany<UnitTenure, $this>
     */
    public function tenures(): HasMany
    {
        return $this->hasMany(UnitTenure::class);
    }

    /** @return HasMany<Bill, $this> */
    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    /** @return HasMany<Payment, $this> */
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
