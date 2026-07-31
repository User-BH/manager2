<?php

namespace App\Http\Resources;

use App\Models\Subscription;
use App\Support\Jalali;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * اشتراک در صفِ بررسیِ ادمینِ کل.
 *
 * از `present()` در `Api/System/SubscriptionReviewController.php` بیرون کشیده شد (R9b). شکلِ خروجی کلمه‌به‌کلمه
 * همان است؛ فقط حالا یک نقطه‌ی حقیقت دارد و افزودنِ فیلدِ تازه فقط همین‌جا
 * انجام می‌شود، نه در هر کنترلری که همان مدل را برمی‌گرداند.
 *
 * کلیدها camelCase‌اند چون مصرف‌کننده TypeScript است؛ تبدیل در همین لایه است
 * تا تغییرِ نامِ ستونِ دیتابیس قراردادِ API را نشکند.
 *
 * @property-read Subscription $resource
 */
class SubscriptionReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $s = $this->resource;

        return [
            'id' => $s->id,
            'complexName' => $s->complex?->name ?? '—',
            'buyerName' => $s->user?->name ?? '—',
            'buyerPhone' => $s->user?->phone,
            'plan' => $s->plan_id ? $s->planRef?->slug : $s->plan->value,
            'planLabel' => $s->planLabel(),
            'months' => (int) $s->months,
            'amount' => (float) $s->amount,
            'amountLabel' => Jalali::money($s->amount),
            'status' => $s->status,
            'statusLabel' => $s->statusLabel(),
            'method' => $s->method,
            'methodLabel' => $s->methodLabel(),
            'paidOn' => $s->receipt_paid_on ? Jalali::date($s->receipt_paid_on) : null,
            'note' => $s->review_note,
            'hasReceipt' => filled($s->receipt_path),
            'receiptUrl' => filled($s->receipt_path)
                ? route('api.system.subscriptions.receipt', $s)
                : null,
            'reviewedBy' => $s->reviewer?->name,
            'reviewedAt' => $s->reviewed_at ? Jalali::dateTime($s->reviewed_at) : null,
            'endsAt' => $s->ends_at ? Jalali::date($s->ends_at) : null,
            'createdAt' => Jalali::dateTime($s->created_at),
        ];
    }
}
