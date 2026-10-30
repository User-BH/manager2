<?php

namespace App\Support;

use App\Models\User;

/**
 * نامی که کاربران از فرستنده می‌بینند (۲۰۲۶-۱۰-۳۰، بندِ ۳ ِ کارفرما).
 *
 * هر پیامی که یکی از مدیرانِ سامانه (سوپر ادمین) به کاربران می‌فرستد — پیامِ پیام‌رسان، دعوت، نظر روی درخواست، پاسخِ
 * بازخورد یا تیکت، اطلاعیه — برای کاربر با نامِ «تیم ساکنا» دیده می‌شود، نه نامِ شخصیِ مدیر. نامِ شخصی فقط در «لاگ
 * فعالیت ← فعالیت مدیران» ِ پنلِ سوپر ادمین می‌ماند.
 */
final class PublicIdentity
{
    public const TEAM = 'تیم ساکنا';

    public static function nameOf(?User $user, string $fallback = 'کاربر حذف‌شده'): string
    {
        if ($user === null) {
            return $fallback;
        }

        return $user->isSuperAdmin() ? self::TEAM : (string) $user->name;
    }
}
