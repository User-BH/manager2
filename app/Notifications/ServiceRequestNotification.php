<?php

namespace App\Notifications;

use App\Models\ServiceRequest;
use Illuminate\Notifications\Notification;

/**
 * خبرِ تازه روی یک درخواست (R25).
 *
 * ─── چرا فقط کانالِ `database` ──────────────────────────────────────────────
 * قاعده‌ی محصول این است که **پیامک فقط برای کدِ یک‌بارمصرف** است. کانالِ
 * دیتابیس یعنی اعلان در همان زنگوله‌ی هدر دیده می‌شود — جایی که کاربر از
 * قبل نگاهش می‌کند.
 *
 * ─── چرا یک کلاس و نه چهار ─────────────────────────────────────────────────
 * «واگذار شد»، «وضعیت عوض شد» و «پیامِ تازه» سه رویدادِ متفاوت‌اند ولی یک
 * شکل دارند: عنوان، یک خط متن، و لینکِ همان درخواست. با چهار کلاسِ جدا،
 * چهار جا باید یادمان می‌ماند لینک را درست بسازیم.
 */
class ServiceRequestNotification extends Notification
{
    public function __construct(
        private readonly ServiceRequest $request,
        private readonly string $title,
        private readonly string $body,
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
            'type' => 'service_request',
            'title' => $this->title,
            'body' => $this->body,
            'serviceRequestId' => $this->request->id,
        ];
    }
}
