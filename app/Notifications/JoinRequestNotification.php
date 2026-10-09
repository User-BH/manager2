<?php

namespace App\Notifications;

use App\Models\ComplexInvitation;
use Illuminate\Notifications\Notification;

/**
 * درخواستِ تازه‌ی عضویت — به مدیرانِ همان مجتمع، فقط در داشبورد (مرحلهٔ ۹ ·
 * اصلاحاتِ پس از فاز ۶؛ تصمیمِ کارفرما: «مدیر از راهِ اعلانِ داشبورد باخبر می‌شود»).
 *
 * همان سازوکارِ اعلانِ شخصیِ پروژه (`database`). پیامک نه: پیامکِ سامانه فقط
 * برای کدِ ورود و یادآورِ بدهی است. شماره‌ی متقاضی در اعلان نمی‌آید؛ نامش و
 * پیوند به صندوقِ درخواست‌ها کافی است.
 */
class JoinRequestNotification extends Notification
{
    public function __construct(private readonly ComplexInvitation $request, private readonly string $applicantName) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'join_request.received',
            'title' => 'درخواستِ تازه‌ی عضویت',
            'body' => $this->applicantName.' درخواستِ پیوستن به مجتمع داده است. در صفحه‌ی ساکنین تأیید یا رد کنید.',
            'joinRequestId' => $this->request->id,
        ];
    }
}
