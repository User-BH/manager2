<?php

namespace App\Http\Resources;

use App\Models\Bill;
use App\Support\Jalali;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * قبضِ ساکن.
 *
 * از `present()` در `Api/MyBillController.php` بیرون کشیده شد (R9b). شکلِ خروجی کلمه‌به‌کلمه
 * همان است؛ فقط حالا یک نقطه‌ی حقیقت دارد و افزودنِ فیلدِ تازه فقط همین‌جا
 * انجام می‌شود، نه در هر کنترلری که همان مدل را برمی‌گرداند.
 *
 * کلیدها camelCase‌اند چون مصرف‌کننده TypeScript است؛ تبدیل در همین لایه است
 * تا تغییرِ نامِ ستونِ دیتابیس قراردادِ API را نشکند.
 *
 * @property-read Bill $resource
 */
class BillResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $bill = $this->resource;

        return [
            'id' => $bill->id,
            'unitLabel' => $bill->unit ? $bill->unit->label() : '—',
            'period' => $bill->period,
            'periodLabel' => Jalali::periodLabel($bill->period),
            'ownerAmount' => (float) $bill->owner_amount,
            'tenantAmount' => (float) $bill->tenant_amount,
            'penaltyAmount' => (float) $bill->penalty_amount,
            'totalAmount' => (float) $bill->total_amount,
            'paidAmount' => (float) $bill->paid_amount,
            'remaining' => (float) $bill->remaining(),
            'status' => $bill->status->value,
            'statusLabel' => $bill->status->label(),
            'dueDate' => $bill->due_date ? Jalali::date($bill->due_date) : null,
            // PDF یک دانلود مستقیم است و صفحه‌ی پرداخت مسیر داخلی SPA
            'pdfUrl' => route('bills.invoice', $bill),
            'payPath' => '/pay/'.$bill->id,
        ];
    }
}
