<?php

namespace App\Http\Resources;

use App\Models\Advertisement;
use App\Support\Jalali;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * بنرِ تبلیغاتیِ صفحه‌ی فرود.
 *
 * از `present()` در `Api/System/AdvertisementController.php` بیرون کشیده شد (R9b). شکلِ خروجی کلمه‌به‌کلمه
 * همان است؛ فقط حالا یک نقطه‌ی حقیقت دارد و افزودنِ فیلدِ تازه فقط همین‌جا
 * انجام می‌شود، نه در هر کنترلری که همان مدل را برمی‌گرداند.
 *
 * کلیدها camelCase‌اند چون مصرف‌کننده TypeScript است؛ تبدیل در همین لایه است
 * تا تغییرِ نامِ ستونِ دیتابیس قراردادِ API را نشکند.
 *
 * @property-read Advertisement $resource
 */
class AdvertisementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $ad = $this->resource;

        return [
            'id' => $ad->id,
            'title' => $ad->title,
            'subtitle' => $ad->subtitle,
            'href' => $ad->href,
            'image' => $ad->displayImageUrl(),
            'isActive' => $ad->is_active,
            'isLive' => $ad->isLive(),
            'sortOrder' => $ad->sort_order,
            'startsAt' => $ad->starts_at?->toDateString(),
            'endsAt' => $ad->ends_at?->toDateString(),
            'startsAtLabel' => $ad->starts_at ? Jalali::date($ad->starts_at) : null,
            'endsAtLabel' => $ad->ends_at ? Jalali::date($ad->ends_at) : null,
            // بنرهای پیش‌فرضِ همراه پروژه فایل آپلودی ندارند
            'isBuiltIn' => ! $ad->image_path,
        ];
    }
}
