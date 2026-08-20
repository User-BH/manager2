<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * هشدارِ سلامتِ سامانه برای سوپرادمین (R43).
 *
 * ─── چرا کانالِ دیتابیس، و نه چیزی که واقعاً بیدارت کند ────────────────────
 * ⚠️ این پروژه عمداً هیچ کانالِ خروجیِ همگانی ندارد: ایمیل کلاً کنار گذاشته
 * شده و پیامک فقط برای کدِ یک‌بارمصرف است. پس تنها جایی که می‌شود هشدار را
 * گذاشت، همان زنگوله‌ی داخلِ پنل است.
 *
 * این یعنی هشدار **فقط وقتی دیده می‌شود که کسی وارد پنل شود** — و اگر خودِ
 * سامانه پایین باشد، کسی نمی‌تواند وارد شود. برای همین این کانال مکمل است،
 * نه جایگزین: پایشِ واقعی باید از بیرون به `/up` بزند. این محدودیت در
 * مستنداتِ R43 صریح نوشته شده.
 */
class SystemHealthNotification extends Notification
{
    /**
     * @param  array<string, string>  $problems  نامِ سنجه ← شرحِ مشکل
     */
    public function __construct(
        private readonly string $status,
        private readonly array $problems,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $title = $this->status === 'down'
            ? 'سامانه سرویس نمی‌دهد'
            : 'سامانه هشدار دارد';

        return [
            'type' => 'system_health',
            'title' => $title,
            'body' => implode('؛ ', array_map(
                fn (string $name, string $detail): string => "{$name}: {$detail}",
                array_keys($this->problems),
                $this->problems,
            )),
            'status' => $this->status,
            'problems' => $this->problems,
        ];
    }
}
