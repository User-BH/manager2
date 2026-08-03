<?php

namespace App\Support;

use App\Enums\NotificationChannelKey;
use App\Models\NotificationSetting;
use App\Models\User;

/**
 * «آیا این کاربر این اعلان را می‌خواهد؟» (R27)
 *
 * ─── چرا یک کلاس و نه یک متد روی `User` ─────────────────────────────────────
 * مصرف‌کننده‌هایش پراکنده‌اند: کارزارِ پیامک، یادآورِ قبض، اعلان‌های درخواست.
 * با متد روی مدل، هر کدام وسوسه می‌شدند شرطِ خودشان را کنارش بگذارند
 * («این یکی مهم است، بفرست») و پیش‌فرض‌ها واگرا می‌شدند.
 *
 * ─── پیش‌فرض: روشن ─────────────────────────────────────────────────────────
 * نبودِ ردیف یعنی کاربر تصمیمی نگرفته، و تصمیم‌نگرفتن یعنی «بفرست». پس
 * جدول فقط به اندازه‌ی خاموش‌کردن‌های واقعی رشد می‌کند.
 */
class NotificationPreferences
{
    public function allows(User $user, NotificationChannelKey $key): bool
    {
        $setting = NotificationSetting::where('user_id', $user->id)
            ->where('channel_key', $key->value)
            ->first();

        return $setting === null || $setting->enabled;
    }

    /**
     * وضعیتِ همه‌ی کانال‌ها برای یک کاربر — برای صفحه‌ی تنظیمات.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(User $user): array
    {
        $saved = NotificationSetting::where('user_id', $user->id)
            ->pluck('enabled', 'channel_key');

        return array_map(
            fn (array $option) => $option + ['enabled' => (bool) ($saved[$option['value']] ?? true)],
            NotificationChannelKey::options(),
        );
    }

    /** روشن/خاموش کردنِ یک کانال. */
    public function set(User $user, NotificationChannelKey $key, bool $enabled): void
    {
        NotificationSetting::updateOrCreate(
            ['user_id' => $user->id, 'channel_key' => $key->value],
            ['enabled' => $enabled],
        );
    }
}
