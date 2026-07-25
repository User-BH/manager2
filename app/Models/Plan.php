<?php

namespace App\Models;

use App\Contracts\PlanCapabilities;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * پکیجِ اشتراکِ پویا که ادمینِ کل تعریف می‌کند.
 *
 * قابلیت‌هایی که کد واقعاً اعمال می‌کند ستونِ مشخص دارند (سقفِ واحد، درگاهِ
 * واقعی، خروجی Excel)؛ `features` فقط برچسب‌های نمایشی است.
 */
class Plan extends Model implements PlanCapabilities
{
    protected $fillable = [
        'name', 'slug', 'price', 'months', 'unit_limit',
        'real_gateway', 'excel_export', 'features', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
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
