<?php

namespace App\Models;

use App\Enums\BillStatus;
use App\Models\Concerns\BelongsToComplex;
use App\Models\Concerns\NeverDeleted;
use App\Support\Jalali;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property BillStatus $status
 * @property CarbonInterface|null $due_date
 * @property int $reminders_sent
 * @property CarbonInterface|null $last_reminded_at
 */
class Bill extends Model
{
    /*
     * ⚠️ `NeverDeleted`: این رکورد ویرایش می‌شود ولی هرگز حذف نه (R51).
     *
     * هیچ عملیاتِ کاربریِ مشروعی به‌نامِ «حذفِ قبض» یا «حذفِ پرداخت»
     * وجود ندارد — اصلاح با تغییرِ مبلغ یا وضعیت انجام می‌شود. تا
     * پیش از این هیچ سدی نبود و یک `->delete()` در کدِ آینده
     * می‌توانست تاریخچه‌ی مالی را ببرد.
     */
    use BelongsToComplex, NeverDeleted;
    use HasFactory;

    protected $fillable = [
        'complex_id', 'unit_id', 'period', 'owner_amount', 'tenant_amount',
        'base_amount', 'penalty_amount', 'discount_amount', 'total_amount',
        'paid_amount', 'status', 'due_date', 'paid_at', 'issued_at', 'breakdown', 'last_reminded_at',
    ];

    /**
     * هر ذخیره‌ی قبض کشِ بدهیِ واحد را از قبض‌ها می‌شمارد (مرحلهٔ ۱۰ · فاز ۱۳؛ بندِ ۱۰۵).
     *
     * پیش‌تر فقط `syncStatus()` این کار را می‌کرد؛ قبضی که بی آن ذخیره می‌شد کش را کهنه می‌گذاشت. حالا ناوردایی به
     * خودِ ذخیره بسته است، نه به یادِ فراخواننده.
     */
    protected static function booted(): void
    {
        static::saved(function (Bill $bill): void {
            if ($bill->relationLoaded('unit') && $bill->unit !== null) {
                $bill->unit->recalculateBalance();

                return;
            }
            Unit::recalculateBalanceFor((int) $bill->unit_id);
        });
    }

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
            'last_reminded_at' => 'datetime',
            'breakdown' => 'array',
        ];
    }

    /**
     * قبض‌هایی که این ساکن حقِ دیدنشان را دارد — **فقط دوره‌های خودش**
     * (مرحلهٔ ۹ · فاز ۶، F4).
     *
     * ─── 🔴 چه چیزی لو می‌رفت ─────────────────────────────────────────────
     * فهرست و جزئیاتِ قبض از «واحدهای جاریِ کاربر» خوانده می‌شد، بی هیچ مرزِ
     * زمانی. مستأجرِ تازه همه‌ی قبض‌های گذشته‌ی واحد را می‌دید — با پرداخت‌ها،
     * روش و تاریخِ پرداختِ مستأجرِ قبلی. تصمیمِ کارفرما: ساکنِ تازه به سابقه و
     * داده‌ی خصوصیِ ساکنِ قبلی دسترسی ندارد.
     *
     * حالا هر قبض فقط وقتی دیده می‌شود که دوره‌اش (`YYYY-MM` ِ شمسی) در بازه‌ی
     * یکی از دوره‌های سکونتِ همین کاربر روی همان واحد باشد. دوره‌ی بی‌تاریخِ
     * شروع (داده‌ی پیش از R26) مرزی ندارد.
     *
     * `$includePast`: دوره‌های بسته‌شده‌ی خودش هم (برای دیدن و پرداختِ قبضِ
     * خودش پس از جابه‌جایی در همان مجتمع)؛ فهرستِ «قبض‌های من» فقط جاری‌ها.
     *
     * @param  Builder<Bill>  $query
     */
    public function scopeVisibleToResident(Builder $query, User $user, bool $includePast = false): void
    {
        $tenures = UnitTenure::query()
            ->where('user_id', $user->id)
            ->when(! $includePast, fn ($q) => $q->where('is_current', true))
            ->get(['unit_id', 'start_date', 'end_date']);

        if ($tenures->isEmpty()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $outer) use ($tenures): void {
            foreach ($tenures as $tenure) {
                $outer->orWhere(function (Builder $q) use ($tenure): void {
                    $q->where('unit_id', $tenure->unit_id);

                    if ($tenure->start_date) {
                        $q->where('period', '>=', Jalali::period($tenure->start_date));
                    }
                    if ($tenure->end_date) {
                        $q->where('period', '<=', Jalali::period($tenure->end_date));
                    }
                });
            }
        });
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function remaining(): float
    {
        return max(0, (float) $this->total_amount - (float) $this->paid_amount);
    }

    /** وضعیت را از پرداخت‌شده می‌سازد؛ کشِ واحد را رویدادِ `saved` به‌روز می‌کند. */
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
    }
}
