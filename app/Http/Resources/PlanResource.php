<?php

namespace App\Http\Resources;

use App\Models\Plan;
use App\Models\PlanPrice;
use App\Support\Jalali;
use App\Support\PlanCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * پکیجِ اشتراک.
 *
 * از `present()` در `Api/System/PlanController.php` بیرون کشیده شد (R9b). شکلِ خروجی کلمه‌به‌کلمه
 * همان است؛ فقط حالا یک نقطه‌ی حقیقت دارد و افزودنِ فیلدِ تازه فقط همین‌جا
 * انجام می‌شود، نه در هر کنترلری که همان مدل را برمی‌گرداند.
 *
 * کلیدها camelCase‌اند چون مصرف‌کننده TypeScript است؛ تبدیل در همین لایه است
 * تا تغییرِ نامِ ستونِ دیتابیس قراردادِ API را نشکند.
 *
 * @property-read Plan $resource
 */
class PlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $p = $this->resource;

        return [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            // null یعنی «برای هر دو نوع» (R67)
            'complex_type' => $p->complex_type?->value,
            'complexTypeLabel' => $p->complex_type?->label() ?? 'هر دو',
            'price' => $p->price,
            'priceLabel' => Jalali::money($p->price),
            'months' => $p->months,
            'unit_limit' => $p->unit_limit,
            'real_gateway' => $p->real_gateway,
            'excel_export' => $p->excel_export,
            'features' => $p->features ?? [],
            'is_active' => $p->is_active,
            'sort_order' => $p->sort_order,
            // ۱۳/۶: سطح و قیمتِ هر مدت؛ پلنِ بی سطح در کارت‌های خرید نمی‌آید
            'tier' => $p->tier?->value,
            'tierLabel' => $p->tier?->label(),
            'description' => $p->description,
            'prices' => $p->prices->map(fn (PlanPrice $price) => [
                'months' => $price->months,
                'price' => $price->price,
                'priceLabel' => Jalali::money($price->price),
                'is_active' => $price->is_active,
            ])->values()->all(),
            // همان چیزی که کارتِ خرید از این پلن نشان می‌دهد (`PlanCatalog`)
            'capabilities' => PlanCatalog::capabilities($p),
            'includesEverything' => PlanCatalog::unlimited($p),
        ];
    }
}
