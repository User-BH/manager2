<?php

namespace App\Http\Resources;

use App\Models\Subscription;
use App\Support\Jalali;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * اشتراکِ مجتمع، از دیدِ مدیرِ همان مجتمع.
 *
 * از `present()` در `Api/SubscriptionController.php` بیرون کشیده شد (R9b). شکلِ خروجی کلمه‌به‌کلمه
 * همان است؛ فقط حالا یک نقطه‌ی حقیقت دارد و افزودنِ فیلدِ تازه فقط همین‌جا
 * انجام می‌شود، نه در هر کنترلری که همان مدل را برمی‌گرداند.
 *
 * کلیدها camelCase‌اند چون مصرف‌کننده TypeScript است؛ تبدیل در همین لایه است
 * تا تغییرِ نامِ ستونِ دیتابیس قراردادِ API را نشکند.
 *
 * @property-read Subscription $resource
 */
class SubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $s = $this->resource;

        return [
            'id' => $s->id,
            'plan' => $s->plan_id ? $s->planRef?->slug : $s->plan->value,
            'planLabel' => $s->planLabel(),
            'status' => $s->status,
            'statusLabel' => $s->statusLabel(),
            'method' => $s->method,
            'methodLabel' => $s->methodLabel(),
            'amount' => (float) $s->amount,
            'amountLabel' => Jalali::money($s->amount),
            'buyerName' => $s->user?->name,
            'startsAt' => $s->starts_at ? Jalali::date($s->starts_at) : null,
            'endsAt' => $s->ends_at ? Jalali::date($s->ends_at) : null,
            'daysLeft' => $s->ends_at ? max(0, (int) now()->diffInDays($s->ends_at, false)) : 0,
            'trackingCode' => $s->tracking_code,
            'reviewNote' => $s->review_note,
            'hasReceipt' => filled($s->receipt_path),
            'createdAt' => Jalali::dateTime($s->created_at),
        ];
    }
}
