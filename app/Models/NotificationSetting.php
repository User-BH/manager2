<?php

namespace App\Models;

use App\Enums\NotificationChannelKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * خاموش‌کردنِ یک نوع اعلان توسط کاربر (R27).
 *
 * ردیف فقط وقتی وجود دارد که کاربر تنظیمی را دست زده باشد؛ نبودِ ردیف یعنی
 * پیش‌فرض. این‌طور جدول به اندازه‌ی تصمیم‌های واقعی رشد می‌کند نه
 * کاربر×کانال.
 *
 * @property int $id
 * @property int $user_id
 * @property NotificationChannelKey $channel_key
 * @property bool $enabled
 */
class NotificationSetting extends Model
{
    protected $fillable = ['user_id', 'channel_key', 'enabled'];

    protected function casts(): array
    {
        return [
            'channel_key' => NotificationChannelKey::class,
            'enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
