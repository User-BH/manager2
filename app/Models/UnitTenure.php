<?php

namespace App\Models;

use App\Enums\ResidentRelation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * یک **دوره‌ی** مالکیت یا سکونت روی یک واحد (R26).
 *
 * ─── چرا مدل، در حالی که جدولِ pivot از قبل بود ─────────────────────────────
 * `unit_user` تا امروز فقط از راهِ رابطه‌ی `belongsToMany` دیده می‌شد، و آن
 * رابطه ذاتاً «وضعیتِ اکنون» را نشان می‌دهد نه «تاریخچه». به همین دلیل هم
 * کدی که ساکن را جابه‌جا می‌کرد از `syncWithoutDetaching` استفاده می‌کرد و
 * ردیفِ قبلی را بازنویسی می‌کرد — رفتاری که برای یک pivotِ ساده درست است و
 * برای یک دفترِ تاریخچه فاجعه.
 *
 * با مدلِ صریح، هر ردیف یک **رویدادِ ثبت‌شده** است: چه کسی، چه واحدی، از کِی
 * تا کِی، با چه سهمی. ردیف‌ها هرگز به‌روزرسانی نمی‌شوند مگر برای بستنشان.
 *
 * @property int $id
 * @property int $complex_id
 * @property int $unit_id
 * @property int $user_id
 * @property ResidentRelation $relation
 * @property float $share_percent
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property bool $is_current
 */
class UnitTenure extends Model
{
    protected $table = 'unit_user';

    protected $fillable = [
        'complex_id', 'unit_id', 'user_id',
        'relation', 'share_percent', 'start_date', 'end_date', 'is_current',
    ];

    protected function casts(): array
    {
        return [
            'relation' => ResidentRelation::class,
            'share_percent' => 'float',
            'start_date' => 'date',
            'end_date' => 'date',
            'is_current' => 'boolean',
        ];
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  Builder<UnitTenure>  $query */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('is_current', true);
    }

    /** @param  Builder<UnitTenure>  $query */
    public function scopeOwners(Builder $query): void
    {
        $query->where('relation', ResidentRelation::Owner->value);
    }

    /**
     * برچسبِ بازه برای نمایش.
     *
     * دوره‌ی باز «تا کنون» است و نه یک تاریخِ خالی؛ کاربر باید تفاوتِ
     * «هنوز ادامه دارد» و «تاریخش را نمی‌دانیم» را ببیند.
     */
    public function isOpen(): bool
    {
        return $this->is_current && $this->end_date === null;
    }
}
