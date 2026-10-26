<?php

namespace App\Notifications;

use App\Models\ComplexInvitation;
use Illuminate\Notifications\Notification;

/**
 * دعوتِ مدیرِ یک مجتمع به حسابی که از پیش هست — مرحلهٔ ۱۳ · فاز ۵ (ایرادات ۱۱، بندهای ۳.۳ تا ۳.۷؛ تصمیمِ ۲.۳-۴).
 *
 * **حسابی، نه مجتمعی:** بی `complexId`، پس در هر زمینه‌ی همان حساب دیده و شمرده می‌شود — داشبوردِ آفتاب، داشبوردِ
 * اولیه، هر جا (`Notifications::inContext`). مجتمعِ مقصد هنوز زمینه‌ی او نیست و نسبت دادنِ اعلان به آن یعنی پنهان‌شدنش.
 *
 * **اعلان ≠ عضویت (بندِ ۳.۵):** این ردیف هیچ اختیاری نمی‌سازد. پذیرش فقط از `InvitationController::accept` است، با
 * کدِ تأییدِ تازه به شماره‌ی خودِ گیرنده، روی دعوتِ **زنده**؛ خواندن، باز کردن یا دیدنِ نامِ مجتمع هیچ‌کدام عضویت نیست.
 *
 * داده‌ها همه از ردیفِ واقعیِ دعوت (`ComplexInvitation`) — نامِ مجتمع، نامِ دعوت‌کننده، نقش و واحد — نه متنِ ثابت.
 */
class InvitationReceivedNotification extends Notification
{
    public function __construct(private readonly ComplexInvitation $invitation) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $invitation = $this->invitation->loadMissing([
            'complex:id,name',
            'unit' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'unit_number'),
            'inviter' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name'),
        ]);

        // مجتمعِ دعوت کلیدِ خارجیِ الزامی است و همیشه هست
        $complex = $invitation->complex->name;
        $role = $invitation->role->label().($invitation->unit ? ' واحدِ '.$invitation->unit->unit_number : '');
        $by = $invitation->inviter?->name ? $invitation->inviter->name.'، مدیرِ «'.$complex.'»،' : 'مدیرِ «'.$complex.'»';

        return [
            'type' => 'invitation.received',
            'title' => 'دعوت به «'.$complex.'»',
            'body' => $by.' شما را به‌عنوانِ '.$role.' دعوت کرده است. برای پیوستن یا رد، دعوت را باز کنید.',
            'invitationId' => $invitation->id,
            // ⚠️ بی `complexId`: اعلانِ حسابی است (بالا)
        ];
    }
}
