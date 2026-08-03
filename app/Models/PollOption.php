<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * یک گزینه‌ی نظرسنجی (R23b).
 *
 * @property int $id
 * @property int $message_poll_id
 * @property string $label
 * @property int $sort_order
 */
class PollOption extends Model
{
    public $timestamps = false;

    protected $fillable = ['message_poll_id', 'label', 'sort_order'];

    /** @return BelongsTo<MessagePoll, $this> */
    public function poll(): BelongsTo
    {
        return $this->belongsTo(MessagePoll::class, 'message_poll_id');
    }

    /** @return HasMany<PollVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class);
    }
}
