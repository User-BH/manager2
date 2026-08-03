<?php

namespace App\Models;

use App\Enums\PollVoterScope;
use App\Enums\PollWeightMode;
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
 * @property PollVoterScope $voter_scope
 * @property PollWeightMode $weight_mode
 * @property int|null $quorum_percent
 * @property bool $allow_change
 */
class MessagePoll extends Model
{
    protected $fillable = [
        'message_id', 'question', 'closes_at',
        'voter_scope', 'weight_mode', 'quorum_percent', 'allow_change',
    ];

    protected function casts(): array
    {
        return [
            'closes_at' => 'datetime',
            'voter_scope' => PollVoterScope::class,
            'weight_mode' => PollWeightMode::class,
            'allow_change' => 'boolean',
        ];
    }

    /**
     * بستنِ دستیِ نظرسنجی توسط مدیر (R24).
     *
     * همان `closes_at` را روی «الان» می‌گذارد و ستونِ جداگانه‌ای برای
     * «بسته‌شده» نمی‌سازد: با دو منبع، دیر یا زود یکی می‌گفت باز است و
     * دیگری بسته.
     */
    public function closeNow(): void
    {
        $this->update(['closes_at' => now()]);
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
