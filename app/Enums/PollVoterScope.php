<?php

namespace App\Enums;

/**
 * چه کسانی حقِ رأی دارند (R24).
 *
 * این با **مخاطبِ پیام** (R23a) یکی نیست و عمداً از آن جدا نگه داشته شده:
 * مخاطب تعیین می‌کند چه کسی نظرسنجی را **می‌بیند**، و این تعیین می‌کند چه
 * کسی می‌تواند **رأی بدهد**. مدیر ممکن است بخواهد همه‌ی ساکنین از تصمیمِ
 * نما باخبر باشند ولی فقط مالکان رأی بدهند.
 */
enum PollVoterScope: string
{
    case Residents = 'residents';
    case Owners = 'owners';

    public function label(): string
    {
        return match ($this) {
            self::Residents => 'همه‌ی ساکنین',
            self::Owners => 'فقط مالکان',
        };
    }
}
