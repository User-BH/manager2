<?php

namespace App\Http\Resources;

use App\Models\ComplexMembership;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\ComplexMembers;
use App\Services\Account\ProfilePhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ساکن در فهرستِ مدیرِ مجتمع.
 *
 * از `present()` در `Api/ResidentController.php` بیرون کشیده شد (R9b). شکلِ خروجی کلمه‌به‌کلمه
 * همان است؛ فقط حالا یک نقطه‌ی حقیقت دارد و افزودنِ فیلدِ تازه فقط همین‌جا
 * انجام می‌شود، نه در هر کنترلری که همان مدل را برمی‌گرداند.
 *
 * کلیدها camelCase‌اند چون مصرف‌کننده TypeScript است؛ تبدیل در همین لایه است
 * تا تغییرِ نامِ ستونِ دیتابیس قراردادِ API را نشکند.
 *
 * @property-read User $resource
 */
class ResidentResource extends JsonResource
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
            'avatarUrl' => ProfilePhoto::url($user),
            'phone' => $user->phone,
            'email' => $user->email,
            'nationalId' => $user->national_id,
            // نقشِ عضویتِ همین مجتمع (۱۰/۳)، نه نقشِ زمینه‌ی فعالِ ساکن
            'role' => ComplexMembers::displayedRole($user)->value,
            'roleLabel' => ComplexMembers::displayedRole($user)->label(),
            /*
             * فعال یعنی عضویتِ همین مجتمع معلق نیست **و** حساب را ادمینِ کل مسدود نکرده (۱۰/۶). وضعیتِ عضویت از
             * همان کوئریِ فهرست می‌آید (`membership_status`)؛ بی‌آن فقط حساب سنجیده می‌شود.
             */
            'isActive' => (bool) $user->is_active && $user->getAttribute('membership_status') !== ComplexMembership::SUSPENDED,
            'canMessage' => (bool) $user->can_message,
            'units' => $user->currentUnits->map(fn (Unit $u) => [
                'id' => $u->id,
                'label' => 'واحد '.$u->unit_number,
            ])->values(),
        ];
    }
}
