<?php

namespace App\Models;

use App\Contracts\PlanCapabilities;
use App\Enums\ComplexType;
use App\Enums\PlanTier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * پکیجِ اشتراکِ پویا که ادمینِ کل تعریف می‌کند.
 *
 * قابلیت‌هایی که کد واقعاً اعمال می‌کند ستونِ مشخص دارند (سقفِ واحد، درگاهِ
 * واقعی، خروجی Excel)؛ `features` فقط برچسب‌های نمایشی است.
 *
 * از ۱۳/۶ هر پلن **یک سطح** (`tier`) برای یک نوع (یا هر دو) است و قیمتِ هر مدت در `plan_prices`. ستون‌های `price` و
 * `months` ِ همین جدول پیشینه‌اند و هیچ مسیرِ خریدی دیگر از آن‌ها نمی‌خواند (`2026_10_27_000001_add_plan_tiers_and_prices`).
 *
 * @property ComplexType|null $complex_type نوعِ مجتمعی که این تعرفه برایش است؛ خالی = هر دو (R67)
 * @property PlanTier|null $tier سطحِ canonical؛ بی سطح = در کارت‌های خرید نمی‌آید
 * @property string|null $description
 */
class Plan extends Model implements PlanCapabilities
{
    protected $fillable = [
        'name', 'description', 'slug', 'complex_type', 'tier', 'price', 'months', 'unit_limit',
        'real_gateway', 'excel_export', 'features', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'complex_type' => ComplexType::class,
            'tier' => PlanTier::class,
            'price' => 'integer',
            'months' => 'integer',
            'unit_limit' => 'integer',
            'real_gateway' => 'boolean',
            'excel_export' => 'boolean',
            'features' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** پکیج‌های فعال و قابلِ خرید، به ترتیبِ چیدمان. */
    public function scopePurchasable(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * 🔴 پکیج‌هایی که این **نوع** مجتمع حق دارد بخرد (R67).
     *
     * ─── چرا این تنها نقطه‌ی حقیقت است ─────────────────────────────────────
     * تعرفه‌ی پلن‌های تجاری و مسکونی متفاوت است. سه جای مستقل در کد پکیج را
     * از روی `slug` پیدا می‌کنند: فهرستِ خرید، خریدِ رسیدی، و خریدِ آنلاین.
     *
     * ⚠️ اگر فقط **فهرست** فیلتر می‌شد، دو مسیرِ خرید همچنان هر slugی را
     * می‌پذیرفتند — یعنی مدیرِ یک مجتمعِ مسکونی می‌توانست شناسه‌ی پکیجِ
     * تجاری را مستقیم POST کند و تعرفه‌ی آن را بگیرد. این باگِ صورت‌حساب
     * است، نه ایرادِ نمایشی؛ و دقیقاً همان چیزی که برنامه‌ی کار با
     * «نه فقط تغییرِ Label» هشدارش را داده بود.
     *
     * پس هر سه مسیر از همین اسکوپ رد می‌شوند و یک تستِ ساختاری می‌سنجد که
     * هیچ‌کس دوباره پکیج را از روی شناسه‌اش بدونِ این فیلتر پیدا نکند.
     *
     * ⚠️ همان متن اینجا **عمداً** به شکلِ کد نوشته نشده: محافظ دنبالِ الگوی
     * فراخوانی می‌گردد و داکبلاکِ خودِ تعریف نباید در تورش بیفتد — درسِ
     * R52، این بار برعکس.
     *
     * ⚠️ `complex_type = null` یعنی «برای هر دو نوع» — همان حالتی که همه‌ی
     * پکیج‌های موجود در آن می‌مانند تا ادمین تعرفه‌ی تجاری را تعریف کند.
     */
    public function scopeForComplexType(Builder $query, ComplexType $type): Builder
    {
        return $query->where(
            fn (Builder $q) => $q->whereNull('complex_type')->orWhere('complex_type', $type->value)
        );
    }

    /** @return HasMany<PlanPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class)->orderBy('months');
    }

    /** قیمتِ قابلِ خریدِ این مدت، یا `null` (مدتِ بی‌قیمت، غیرفعال یا بیرون از شش مدت) */
    public function priceFor(int $months): ?int
    {
        if (! in_array($months, PlanTier::DURATIONS, true)) {
            return null;
        }

        $row = $this->prices->firstWhere('months', $months);

        return $row?->isPurchasable() ? $row->price : null;
    }

    /**
     * 🔴 کارت‌های خریدِ یک نوع مجتمع (بندهای ۱۱ تا ۱۵) — **تنها** فهرستی که هم صفحه نشان می‌دهد و هم سرور هنگامِ خرید
     * می‌پذیرد.
     *
     * برای هر سطح حداکثر یک پلن: پلنِ مخصوصِ همان نوع بر پلنِ «هر دو نوع» مقدم است. این همان ترتیبِ راه‌اندازیِ تعرفه‌ی
     * تجاری است (`docs/COMPLEX_TYPES.md`): تا پلنِ تجاریِ یک سطح ساخته نشده، مجتمعِ تجاری پلنِ مشترک را می‌بیند؛ از لحظه‌ی
     * ساخت، فقط پلنِ تجاری را. پلنِ سایه‌خورده با slug ِ مستقیم هم خریدنی نیست — وگرنه تعرفه‌ی ارزان‌ترِ نوعِ دیگر دورزدنی
     * بود. پلنِ بی سطح یا بی هیچ مدتِ قیمت‌دار این‌جا نیست.
     *
     * @return Collection<int, Plan>
     */
    public static function catalogFor(ComplexType $type): Collection
    {
        return Plan::query()->where('is_active', true)->whereNotNull('tier')->forComplexType($type)
            ->with('prices')->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (Plan $p) => $p->prices->contains(fn (PlanPrice $price) => $price->isPurchasable()))
            ->sortBy(fn (Plan $p) => [$p->tier?->rank(), $p->complex_type === null ? 1 : 0, $p->sort_order, $p->id])
            ->unique(fn (Plan $p) => $p->tier?->value)
            ->sortBy(fn (Plan $p) => $p->tier?->rank())
            ->values();
    }

    /* ---------------- PlanCapabilities ---------------- */

    public function unitLimit(): ?int
    {
        return $this->unit_limit;
    }

    public function allowsRealGateway(): bool
    {
        return $this->real_gateway;
    }

    public function allowsExcelExport(): bool
    {
        return $this->excel_export;
    }

    public function planLabel(): string
    {
        return $this->name;
    }
}
