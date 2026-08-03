<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * رسیدِ خواندنِ یک پیام (R23b).
 *
 * وجودِ ردیف یعنی «خوانده شد». نبودش یعنی خوانده‌نشده — پرچمِ `false`
 * نداریم، چون آن‌وقت جدول باید به اندازه‌ی پیام×کاربر پر می‌شد.
 *
 * @property int $message_id
 * @property int $user_id
 */
class MessageRead extends Model
{
    public $timestamps = false;

    protected $table = 'message_reads';

    protected $fillable = ['message_id', 'user_id', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
