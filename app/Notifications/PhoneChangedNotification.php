<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * «شماره‌ی حسابتان عوض شد» — فقط در داشبورد (مرحلهٔ ۹).
 *
 * پیامک نه: پیامکِ سامانه فقط کدِ ورود و یادآورِ بدهی است. فقط چهار رقمِ آخرِ
 * شماره‌ی تازه می‌آید.
 */
class PhoneChangedNotification extends Notification
{
    public function __construct(private readonly string $newPhone) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'account.phone_changed',
            'title' => 'شماره‌ی حساب تغییر کرد',
            'body' => 'شماره‌ی حسابِ شما به شماره‌ی منتهی به '.substr($this->newPhone, -4)
                .' تغییر کرد و دستگاه‌های دیگر خارج شدند. اگر این کار را نکرده‌اید، با پشتیبانی تماس بگیرید.',
        ];
    }
}
