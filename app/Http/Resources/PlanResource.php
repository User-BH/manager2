<?php

namespace App\Http\Resources;

use App\Models\Plan;
use App\Support\Jalali;
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
            'price' => $p->price,
            'priceLabel' => Jalali::money($p->price),
            'months' => $p->months,
            'unit_limit' => $p->unit_limit,
            'real_gateway' => $p->real_gateway,
            'excel_export' => $p->excel_export,
            'features' => $p->features ?? [],
            'is_active' => $p->is_active,
            'sort_order' => $p->sort_order,
        ];
    }
}
