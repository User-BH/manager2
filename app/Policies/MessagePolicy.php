<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;

/**
 * پیام‌های مسنجرِ مجتمع.
 *
 * پیام‌ها پاک نمی‌شوند، فقط مخفی می‌شوند — و این کارِ مدیر است. جداسازیِ
 * مجتمع را `ComplexScope` انجام می‌دهد، پس اینجا فقط نقش می‌ماند.
 */
class MessagePolicy
{
    public function hide(User $user, Message $message): bool
    {
        return $user->isAdmin();
    }

    /**
     * بستنِ نظرسنجی (R24).
     *
     * روی `Message` نشسته و نه `MessagePoll`، چون نظرسنجی موجودیتِ مستقل
     * نیست: مالکِ واقعی همان پیام است و `ComplexScope` هم روی همان اعمال
     * می‌شود.
     */
    public function closePoll(User $user, Message $message): bool
    {
        return $user->isAdmin();
    }
}
