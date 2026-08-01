<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Support\Jalali;
use Illuminate\Notifications\Notification;

/**
 * نتیجه‌ی بررسیِ رسید، برای ساکنی که آن را فرستاده.
 *
 * ─── چرا فقط کانالِ `database` ──────────────────────────────────────────────
 * قاعده‌ی محصول این است که **پیامک فقط برای کدِ یک‌بارمصرف** است، پس اینجا
 * پیامکی نمی‌رود. ایمیل هم پیکربندی نشده. کانالِ دیتابیس یعنی اعلان در همان
 * زنگوله‌ی هدر دیده می‌شود — جایی که کاربر از قبل نگاهش می‌کند.
 *
 * افزودنِ کانالِ تازه در آینده فقط تغییرِ `via()` است و به کدِ مالی دست
 * نمی‌خورد.
 */
class PaymentReviewedNotification extends Notification
{
    public function __construct(
        private readonly Payment $payment,
        private readonly bool $approved,
        private readonly ?string $note = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $amount = (float) $this->payment->amount;

        return [
            'type' => $this->approved ? 'payment.approved' : 'payment.rejected',
            'title' => $this->approved ? 'رسید شما تایید شد' : 'رسید شما تایید نشد',
            'body' => $this->approved
                ? 'پرداختِ '.Jalali::digits(number_format($amount)).' تومان تایید و بدهیِ واحد تسویه شد.'
                : 'رسیدِ '.Jalali::digits(number_format($amount)).' تومان تایید نشد.'
                    .($this->note ? ' دلیل: '.$this->note : ''),
            // برای اینکه کلیکِ روی اعلان کاربر را به همان قبض ببرد
            'billId' => $this->payment->bill_id,
            'paymentId' => $this->payment->id,
        ];
    }
}
