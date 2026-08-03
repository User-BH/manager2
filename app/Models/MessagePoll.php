<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * نظرسنجیِ درون‌چت (R23b).
 *
 * نظرسنجی به یک **پیام** بسته است، نه موجودیتی جدا. این‌طور مخاطب‌دهی
 * (R23a)، مخفی‌کردن و رسیدِ خواندن همگی رایگان به دست می‌آیند و لازم نیست
 * دوباره نوشته شوند.
 *
 * @property int $id
 * @property int $message_id
 * @property string $question
 * @property Carbon|null $closes_at
 */
class MessagePoll extends Model
{
    protected $fillable = ['message_id', 'question', 'closes_at'];

    protected function casts(): array
    {
        return ['closes_at' => 'datetime'];
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** @return HasMany<PollOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class)->orderBy('sort_order');
    }

    /** @return HasMany<PollVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class);
    }

    public function isClosed(): bool
    {
        return $this->closes_at !== null && $this->closes_at->isPast();
    }
}
