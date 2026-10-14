<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\Account\ProfilePhoto;
use App\Services\Messenger\MessagingAccess;
use App\Support\Jalali;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * پروفایلِ خودِ کاربر.
 *
 * از `present()` در `Api/ProfileController.php` بیرون کشیده شد (R9b). شکلِ خروجی کلمه‌به‌کلمه
 * همان است؛ فقط حالا یک نقطه‌ی حقیقت دارد و افزودنِ فیلدِ تازه فقط همین‌جا
 * انجام می‌شود، نه در هر کنترلری که همان مدل را برمی‌گرداند.
 *
 * کلیدها camelCase‌اند چون مصرف‌کننده TypeScript است؛ تبدیل در همین لایه است
 * تا تغییرِ نامِ ستونِ دیتابیس قراردادِ API را نشکند.
 *
 * @property-read User $resource
 */
class ProfileResource extends JsonResource
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
            // تصویرِ canonical ِ حساب (مرحلهٔ ۱۰ · فاز ۷)
            'avatarUrl' => ProfilePhoto::url($user),
            'firstName' => $user->first_name,
            'lastName' => $user->last_name,
            'needsName' => $user->needsNameCompletion(),
            'phone' => $user->phone,
            'email' => $user->email,
            'nationalId' => $user->national_id,
            'birthDate' => $user->birth_date?->toDateString(),
            'birthDateLabel' => $user->birth_date ? Jalali::date($user->birth_date) : null,
            'emergencyPhone' => $user->emergency_phone,
            'address' => $user->address,
            'bio' => $user->bio,
            'role' => $user->role->value,
            'roleLabel' => $user->role->label(),
            'isAdmin' => $user->isAdmin(),
            'isActive' => (bool) $user->is_active,
            // در مجتمعِ فعال (۱۰/۹)
            'canMessage' => $user->complex_id === null || ! MessagingAccess::blocked($user, (int) $user->complex_id),
            'joinedAt' => Jalali::date($user->created_at),
            'complex' => $user->complex ? [
                'id' => $user->complex->id,
                'name' => $user->complex->name,
                'type' => $user->complex->type->value,
                'typeLabel' => $user->complex->type->label(),
            ] : null,
        ];
    }
}
