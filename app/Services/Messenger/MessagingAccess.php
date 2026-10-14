<?php

namespace App\Services\Messenger;

use App\Models\Complex;
use App\Models\ComplexMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * «این شخص در این مجتمع پیام می‌فرستد؟» — از عضویتِ همان مجتمع، نه از حساب (مرحلهٔ ۱۰ · فاز ۹؛ یافته‌ی ۱۰/۶).
 *
 * تا ۱۰/۹ این تصمیم `users.can_message` بود: مدیرِ مجتمعِ الف با یک کلیک پیام‌رسانِ همان شخص را در مجتمعِ ب هم
 * می‌بست. حالا `complex_memberships.messaging_blocked_at` ِ همان مجتمع؛ مجتمع‌های دیگر دست نمی‌خورند.
 *
 * ⚠️ عمداً `DB::table` با `complex_id` ِ صریح، مثلِ `ComplexMembers`: مجتمع همین‌جا داده شده است. ادمینِ کل عضویت
 * ندارد و بسته نمی‌شود.
 */
final class MessagingAccess
{
    public static function blocked(User $user, int $complexId): bool
    {
        return DB::table('complex_memberships')
            ->where('user_id', $user->id)
            ->where('complex_id', $complexId)
            ->whereIn('status', ComplexMembership::LIVE)
            ->whereNotNull('messaging_blocked_at')
            ->exists();
    }

    /** پیام‌رسانِ مجتمع روشن است و این عضو در آن بسته نیست. */
    public static function allowed(User $user, ?Complex $complex): bool
    {
        return $complex !== null && $complex->messenger_enabled && ! self::blocked($user, $complex->id);
    }

    /** بستن یا بازکردن در **همین** مجتمع؛ وضعیتِ تازه را برمی‌گرداند (true یعنی باز). */
    public static function toggle(User $user, int $complexId): bool
    {
        $blocked = self::blocked($user, $complexId);

        DB::table('complex_memberships')
            ->where('user_id', $user->id)
            ->where('complex_id', $complexId)
            ->whereIn('status', ComplexMembership::LIVE)
            ->update(['messaging_blocked_at' => $blocked ? null : now(), 'updated_at' => now()]);

        return $blocked;
    }
}
