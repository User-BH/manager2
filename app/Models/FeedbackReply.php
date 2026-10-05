<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * پاسخِ ادمینِ کل به یک بازخورد (مرحلهٔ ۸ · فاز ۶).
 *
 * @property int $id
 * @property int $feedback_id
 * @property int|null $author_id
 * @property int|null $recipient_id
 * @property string $body
 * @property Carbon|null $created_at
 */
class FeedbackReply extends Model
{
    protected $fillable = ['feedback_id', 'author_id', 'recipient_id', 'body'];

    /** @return BelongsTo<Feedback, $this> */
    public function feedback(): BelongsTo
    {
        return $this->belongsTo(Feedback::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
