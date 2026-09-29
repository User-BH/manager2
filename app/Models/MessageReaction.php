<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * واکنش به پیام (مرحلهٔ ۶ · فاز ۱۴).
 *
 * هر کس روی هر پیام یک واکنش دارد و فقط از فهرستِ ثابتِ `ALLOWED` —
 * ورودیِ آزاد یعنی متنِ دلخواه زیرِ پیامِ دیگران، بی‌آنکه نوشتنش پیام باشد.
 *
 * @property int $message_id
 * @property int $user_id
 * @property string $emoji
 */
class MessageReaction extends Model
{
    public const ALLOWED = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

    public const UPDATED_AT = null;

    protected $fillable = ['message_id', 'user_id', 'emoji'];
}
