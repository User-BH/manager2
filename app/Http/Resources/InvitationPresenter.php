<?php

namespace App\Http\Resources;

use App\Models\ComplexInvitation;
use App\Support\Jalali;

/**
 * شکلِ دعوت برای سه بیننده (مرحلهٔ ۹ · فاز ۶).
 *
 * ⚠️ هیچ‌کدام `token_hash` یا `live_key` نمی‌دهند؛ توکنِ خام فقط یک بار، در
 * پاسخِ ساختِ دعوت، به دعوت‌کننده می‌رسد (`link`).
 */
final class InvitationPresenter
{
    /**
     * پیوندِ یک‌بارمصرف — فقط از توکنِ خامی که همین حالا ساخته شده.
     *
     * توکن پس از `#` است: بخشِ fragment هرگز به سرور فرستاده نمی‌شود (لاگِ
     * دسترسی، هدرِ Referer) و در بازدیدِ صفحه هم به آمار نمی‌رود.
     */
    public static function link(string $token): string
    {
        return url('/invite').'#'.$token;
    }

    /**
     * برای مدیر (دعوت‌های فرستاده‌ی این مجتمع).
     *
     * @return array<string, mixed>
     */
    public static function forSender(ComplexInvitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'phone' => $invitation->phone,
            'role' => $invitation->role->value,
            'roleLabel' => $invitation->role->label(),
            'unitLabel' => $invitation->unit ? 'واحد '.$invitation->unit->unit_number : null,
            'expired' => $invitation->isExpired(),
            'expiresAt' => $invitation->expires_at ? Jalali::date($invitation->expires_at) : null,
            'createdAt' => $invitation->created_at?->toIso8601String(),
        ];
    }

    /**
     * برای صاحبِ شماره (داشبوردِ اولیه).
     *
     * @return array<string, mixed>
     */
    public static function forRecipient(ComplexInvitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'complexName' => $invitation->complex?->name,
            'complexAddress' => $invitation->complex?->address,
            'unitLabel' => $invitation->unit ? 'واحد '.$invitation->unit->unit_number : null,
            'roleLabel' => $invitation->role->label(),
            'invitedBy' => $invitation->inviter?->name,
            'expiresAt' => $invitation->expires_at ? Jalali::date($invitation->expires_at) : null,
            'createdAt' => $invitation->created_at?->toIso8601String(),
        ];
    }

    /**
     * برای دارنده‌ی پیوند، پیش از ورود.
     *
     * فقط آنچه برای تصمیم لازم است: کدام مجتمع، چه نقشی، و دو رقمِ آخرِ شماره
     * تا بداند با کدام شماره وارد شود. نشانی، نامِ دعوت‌کننده و شماره‌ی کامل نه:
     * پیوند ممکن است به دستِ کسِ دیگری افتاده باشد.
     *
     * @return array<string, mixed>
     */
    public static function forLinkHolder(ComplexInvitation $invitation): array
    {
        return [
            'complexName' => $invitation->complex?->name,
            'roleLabel' => $invitation->role->label(),
            'unitLabel' => $invitation->unit ? 'واحد '.$invitation->unit->unit_number : null,
            'phoneHint' => '09•••••••'.substr((string) $invitation->phone, -2),
            'expiresAt' => $invitation->expires_at ? Jalali::date($invitation->expires_at) : null,
        ];
    }
}
