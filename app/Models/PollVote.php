<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * رأیِ یک کاربر در یک نظرسنجی (R23b).
 *
 * قیدِ یکتا روی (نظرسنجی، کاربر) است نه (گزینه، کاربر) — پس هیچ‌کس
 * نمی‌تواند دو گزینه را هم‌زمان انتخاب کند و تعویضِ رأی یعنی به‌روزرسانیِ
 * همان ردیف.
 *
 * @property int $message_poll_id
 * @property int $poll_option_id
 * @property int $user_id
 */
class PollVote extends Model
{
    protected $fillable = ['message_poll_id', 'poll_option_id', 'user_id'];

    /** @return BelongsTo<PollOption, $this> */
    public function option(): BelongsTo
    {
        return $this->belongsTo(PollOption::class, 'poll_option_id');
    }
}
