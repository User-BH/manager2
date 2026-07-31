<?php

namespace App\Http\Resources;

use App\Models\Payment;
use App\Support\Jalali;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * رسیدِ پرداخت در صفِ بررسیِ مدیر.
 *
 * از `present()` در `Api/PaymentReviewController.php` بیرون کشیده شد (R9b). شکلِ خروجی کلمه‌به‌کلمه
 * همان است؛ فقط حالا یک نقطه‌ی حقیقت دارد و افزودنِ فیلدِ تازه فقط همین‌جا
 * انجام می‌شود، نه در هر کنترلری که همان مدل را برمی‌گرداند.
 *
 * کلیدها camelCase‌اند چون مصرف‌کننده TypeScript است؛ تبدیل در همین لایه است
 * تا تغییرِ نامِ ستونِ دیتابیس قراردادِ API را نشکند.
 *
 * @property-read Payment $resource
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payment = $this->resource;

        return [
            'id' => $payment->id,
            'amount' => (float) $payment->amount,
            'method' => $payment->method?->value,
            'methodLabel' => $payment->method?->label(),
            'status' => $payment->status->value,
            'statusLabel' => $payment->status->label(),
            'unitLabel' => $payment->unit ? 'واحد '.$payment->unit->unit_number : '—',
            'payerName' => $payment->user?->name ?? '—',
            'billPeriod' => $payment->bill ? Jalali::periodLabel($payment->bill->period) : null,
            'description' => $payment->description,
            'hasReceipt' => filled($payment->receipt_path),
            'receiptUrl' => filled($payment->receipt_path)
                ? route('api.payments.receipt', $payment)
                : null,
            'createdAt' => Jalali::dateTime($payment->created_at),
            'paidAt' => $payment->paid_at ? Jalali::dateTime($payment->paid_at) : null,
        ];
    }
}
