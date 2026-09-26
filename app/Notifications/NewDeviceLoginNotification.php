<?php

namespace App\Notifications;

use App\Support\Jalali;
use Illuminate\Notifications\Notification;

/**
 * «با حسابِ شما از دستگاهی تازه وارد شدند» (مرحلهٔ ۵ · فاز ۱۰).
 *
 * متن عمداً می‌گوید اگر خودتان نبودید چه کنید: هشداری که راهِ واکنش
 * نمی‌دهد فقط نگرانی می‌سازد.
 */
class NewDeviceLoginNotification extends Notification
{
    public function __construct(
        private readonly string $device,
        private readonly ?string $ip,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'security.new_device',
            'title' => 'ورود از دستگاهی تازه',
            'body' => sprintf(
                '%s، %s%s. اگر خودتان نبودید، همین حالا رمز عبور را عوض کنید و در پروفایل، بخشِ «نشست‌ها»، همه‌ی دستگاه‌های دیگر را خارج کنید.',
                Jalali::dateTime(now()),
                $this->device,
                $this->ip ? ' · IP '.$this->ip : '',
            ),
            'url' => '/profile',
        ];
    }
}
