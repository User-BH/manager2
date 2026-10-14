<?php

namespace App\Models;

use App\Enums\OccupancyStatus;
use App\Enums\ResidentRelation;
use App\Models\Concerns\BelongsToComplex;
use App\Models\Concerns\KeepsHistory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

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
 *
 * @property OccupancyStatus|null $occupancy_status
 * @property string|null $block
 * @property string|null $unit_type
 * @property int|null $rooms_count
 * @property int|null $bedrooms_count
 * @property int|null $bathrooms_count
 * @property string|null $parking_numbers
 * @property string|null $storage_numbers
 * @property bool|null $has_terrace
 * @property bool|null $has_balcony
 * @property string|null $orientation
 * @property array<string, string>|null $meters
 * @property string|null $description
 * @property Carbon|null $information_completed_at
 */
class Unit extends Model
{
    // نرم حذف می‌شود؛ `forceDelete` بسته است (۱۰/۱۴؛ بندِ ۷۹)
    use BelongsToComplex, HasFactory, KeepsHistory, SoftDeletes;

    protected $fillable = [
        'complex_id', 'building_id', 'unit_number', 'floor', 'area',
        'residents_count', 'parking_count', 'storage_count', 'occupancy_status',
        'coefficient', 'uses_elevator', 'notes', 'is_active',
    ];

    /**
     * `balance` کشِ بدهیِ واحد است، نه داده‌ی مستقل (مرحلهٔ ۱۰ · فاز ۱۳؛ بندهای ۲ و ۱۰۵).
     *
     * منبعِ حقیقت قبض‌ها هستند: `balance = Σ (total_amount − paid_amount)` ِ قبض‌های واحد. پس `balance` در `fillable`
     * نیست و هر ذخیره‌ای که آن را عوض کند رد می‌شود — تنها راهِ نوشتنش `recalculateBalance()` است که از قبض‌ها
     * می‌شمارد. ناهمخوانی را `finance:check` می‌یابد.
     */
    protected static function booted(): void
    {
        static::saving(function (Unit $unit): void {
            if ($unit->isDirty('balance') && (float) $unit->balance !== (float) $unit->getOriginal('balance', 0)) {
                throw new LogicException('مانده‌ی واحد فقط از قبض‌ها حساب می‌شود (recalculateBalance)، نه با مقداردهیِ مستقیم.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'area' => 'decimal:2',
            'coefficient' => 'decimal:4',
            'balance' => 'decimal:2',
            'uses_elevator' => 'boolean',
            'is_active' => 'boolean',
            'occupancy_status' => OccupancyStatus::class,
            // «اطلاعاتِ واحد» (مرحلهٔ ۱۰ · فاز ۷)
            'has_terrace' => 'boolean',
            'has_balcony' => 'boolean',
            'meters' => 'array',
            'information_completed_at' => 'datetime',
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

    /**
     * کشِ بدهی را از قبض‌ها دوباره می‌شمارد — **در یک دستور**.
     *
     * خواندنِ جمع و نوشتنِ جدا (روشِ پیشین) با دو پرداختِ هم‌زمان می‌توانست جمعِ کهنه را آخر بنویسد؛ `UPDATE … SET
     * balance = (SELECT SUM …)` جمع را همان لحظه‌ی نوشتن از قبض‌های ثبت‌شده می‌خواند.
     */
    public function recalculateBalance(): void
    {
        self::recalculateBalanceFor($this->id);

        $this->setRawAttributes(
            ['balance' => DB::table('units')->where('id', $this->id)->value('balance')] + $this->getAttributes(),
        );
        $this->syncOriginalAttribute('balance');
    }

    /** همان، بی بارکردنِ مدل — برای رویدادِ ذخیره‌ی قبض */
    public static function recalculateBalanceFor(int $unitId): void
    {
        DB::update(
            'UPDATE units SET balance = (SELECT COALESCE(SUM(bills.total_amount - bills.paid_amount), 0) FROM bills WHERE bills.unit_id = units.id) WHERE id = ?',
            [$unitId],
        );
    }
}
