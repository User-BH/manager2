<?php

namespace App\Notifications;

use App\Models\Reminder;
use App\Support\Jalali;
use Illuminate\Notifications\Notification;

/**
 * لحظه‌ی یکی از نمونه‌های یادآور رسید — اعلان در زنگوله (مرحلهٔ ۱۳ · فاز ۷؛ ایرادات ۱۰، بندهای ۸۶، ۸۷ و ۹۹).
 *
 * **مجتمعی، نه سراسری:** `complexId` همان زمینه‌ای است که یادآور در آن ساخته شد؛ پس یادآورِ مجتمعِ الف در زمینه‌ی ب نه
 * دیده و نه شمرده می‌شود (`Notifications::inContext`). یادآورِ سوپر ادمین زمینه‌ی سکوست (`complexId` ِ null).
 *
 * متن از ردیفِ واقعی و تاریخِ همان نمونه، شمسی — تاریخِ رویداد جدا از لحظه‌ی یادآوری (بندِ ۷۸).
 */
class ReminderDueNotification extends Notification
{
    public function __construct(private readonly Reminder $reminder, private readonly string $occurrence) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $when = Jalali::date($this->occurrence, 'j F Y');
        $lead = $this->reminder->lead_days === 0
            ? 'امروز'
            : Jalali::digits($this->reminder->lead_days).' روزِ دیگر';

        return [
            'type' => 'reminder.due',
            'title' => 'یادآور: '.$this->reminder->title,
            'body' => 'رویداد '.$lead.'، '.$when.'.'.($this->reminder->description ? ' '.$this->reminder->description : ''),
            'reminderId' => $this->reminder->id,
            'occurrence' => $this->occurrence,
            'complexId' => $this->reminder->complex_id,
        ];
    }
}
