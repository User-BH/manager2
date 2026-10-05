<?php

namespace App\Notifications;

use App\Models\FeedbackReply;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * پاسخِ تیمِ ساکنا به بازخوردِ کاربر — فقط داخلِ داشبوردِ خودش (مرحلهٔ ۸ · فاز ۶).
 *
 * از همان سازوکارِ اعلانِ شخصیِ پروژه (`database`): خوانده/نخوانده، فقط در
 * `notifications` ِ همان کاربر. پیامک نه — قاعده‌ی پروژه پیامک را فقط برای کدِ
 * ورود و یادآورِ بدهی می‌خواهد.
 */
class FeedbackReplyNotification extends Notification
{
    public function __construct(private readonly FeedbackReply $reply) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'feedback.reply',
            'title' => 'پاسخِ تیمِ ساکنا به بازخوردِ شما',
            // متن به‌صورتِ متن ذخیره و نمایش داده می‌شود؛ هیچ‌جا HTML نیست
            'body' => $this->reply->body,
            'feedbackExcerpt' => Str::limit((string) $this->reply->feedback?->message, 120),
            'feedbackReplyId' => $this->reply->id,
        ];
    }
}
