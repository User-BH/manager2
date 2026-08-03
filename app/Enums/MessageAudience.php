<?php

namespace App\Enums;

/**
 * مخاطبِ یک پیام (R23).
 *
 * قاعده‌ی محصول ساده است و در دو جمله جا می‌شود:
 *   • ساکن **فقط** می‌تواند به مدیریت پیام بدهد.
 *   • مدیر می‌تواند به همه، یا به یک/چند واحدِ انتخابی پیام بدهد.
 */
enum MessageAudience: string
{
    /** ساکن → مدیریت. خصوصیِ همان واحد است. */
    case Management = 'management';

    /** مدیر → همه‌ی اهالیِ مجتمع. */
    case All = 'all';

    /** مدیر → واحدهای انتخاب‌شده. */
    case Units = 'units';

    /** آیا ساکنِ عادی اجازه‌ی فرستادنِ این نوع پیام را دارد؟ */
    public function isResidentAllowed(): bool
    {
        return $this === self::Management;
    }

    public function label(): string
    {
        return match ($this) {
            self::Management => 'به مدیریت',
            self::All => 'به همه',
            self::Units => 'به واحدهای انتخابی',
        };
    }
}
