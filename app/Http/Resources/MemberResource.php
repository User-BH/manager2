<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\Jalali;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * عضوِ سامانه در پنلِ ادمینِ کل.
 *
 * از `present()` در `Api/System/MemberController.php` بیرون کشیده شد (R9b). شکلِ خروجی کلمه‌به‌کلمه
 * همان است؛ فقط حالا یک نقطه‌ی حقیقت دارد و افزودنِ فیلدِ تازه فقط همین‌جا
 * انجام می‌شود، نه در هر کنترلری که همان مدل را برمی‌گرداند.
 *
 * کلیدها camelCase‌اند چون مصرف‌کننده TypeScript است؛ تبدیل در همین لایه است
 * تا تغییرِ نامِ ستونِ دیتابیس قراردادِ API را نشکند.
 *
 * @property-read User $resource
 */
class MemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'role' => $user->role->value,
            'roleLabel' => $user->role->label(),
            'isActive' => (bool) $user->is_active,
            'complex' => $user->complex ? ['id' => $user->complex->id, 'name' => $user->complex->name] : null,
            'registeredAt' => Jalali::date($user->created_at),
        ];
    }
}
