<?php

namespace App\Http\Resources;

use App\Enums\ExpenseCategory;
use App\Models\ChargeRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * قاعده‌ی محاسبه‌ی شارژ.
 *
 * از `present()` در `Api/ChargeRuleController.php` بیرون کشیده شد (R9b). شکلِ خروجی کلمه‌به‌کلمه
 * همان است؛ فقط حالا یک نقطه‌ی حقیقت دارد و افزودنِ فیلدِ تازه فقط همین‌جا
 * انجام می‌شود، نه در هر کنترلری که همان مدل را برمی‌گرداند.
 *
 * کلیدها camelCase‌اند چون مصرف‌کننده TypeScript است؛ تبدیل در همین لایه است
 * تا تغییرِ نامِ ستونِ دیتابیس قراردادِ API را نشکند.
 *
 * @property-read ChargeRule $resource
 */
class ChargeRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $rule = $this->resource;

        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'type' => $rule->type->value,
            'typeLabel' => $rule->type->label(),
            'isPoolBased' => $rule->type->isPoolBased(),
            'category' => $rule->category->value,
            'categoryLabel' => $rule->category === ExpenseCategory::Owner ? 'مالکانه' : 'مستاجرانه',
            'config' => $rule->config ?? [],
            'poolAmount' => $rule->pool_amount !== null ? (float) $rule->pool_amount : null,
            'isActive' => (bool) $rule->is_active,
        ];
    }
}
