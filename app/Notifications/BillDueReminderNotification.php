<?php

namespace App\Notifications;

use App\Models\Bill;
use App\Support\Jalali;
use Illuminate\Notifications\Notification;

/**
 * یادآوریِ قبضِ سررسیدشده (R22).
 *
 * ─── چرا پیامک نمی‌رود ─────────────────────────────────────────────────────
 * قاعده‌ی محصول این است که **پیامک فقط برای کدِ یک‌بارمصرف** است. یادآوریِ
 * شارژ عمداً پیامکی نیست (همان تصمیمی که در `53e3ce0` گرفته شد)، پس اینجا
 * هم فقط کانالِ دیتابیس — یعنی زنگوله‌ی هدر، جایی که کاربر از قبل نگاه
 * می‌کند.
 */
class BillDueReminderNotification extends Notification
{
    public function __construct(
        private readonly Bill $bill,
        private readonly int $daysOverdue,
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
        return [
            'type' => 'bill.due_reminder',
            'title' => 'قبض پرداخت‌نشده دارید',
            'body' => sprintf(
                'قبض دوره‌ی %s با مهلت %s، %d روز از سررسیدش گذشته و %s باقی مانده است.',
                $this->bill->period,
                Jalali::date($this->bill->due_date),
                $this->daysOverdue,
                number_format($this->bill->remaining()),
            ),
            'billId' => $this->bill->id,
            'url' => '/my-bills',
        ];
    }
}
