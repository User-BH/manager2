<?php

namespace App\Models;

use App\Contracts\PlanCapabilities;
use App\Enums\ComplexType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * پکیجِ اشتراکِ پویا که ادمینِ کل تعریف می‌کند.
 *
 * قابلیت‌هایی که کد واقعاً اعمال می‌کند ستونِ مشخص دارند (سقفِ واحد، درگاهِ
 * واقعی، خروجی Excel)؛ `features` فقط برچسب‌های نمایشی است.
 *
 * @property ComplexType|null $complex_type نوعِ مجتمعی که این تعرفه برایش است؛ خالی = هر دو (R67)
 */
class Plan extends Model implements PlanCapabilities
{
    protected $fillable = [
        'name', 'slug', 'complex_type', 'price', 'months', 'unit_limit',
        'real_gateway', 'excel_export', 'features', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'complex_type' => ComplexType::class,
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
