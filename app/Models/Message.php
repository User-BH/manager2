<?php

namespace App\Models;

use App\Enums\MessageAudience;
use App\Models\Concerns\BelongsToComplex;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int|null $conversation_id
 * @property string|null $client_id
 * @property MessageAudience $audience
 * @property int|null $unit_id
 * @property string|null $attachment_path
 * @property string|null $attachment_name
 * @property string|null $attachment_kind
 */
class Message extends Model
{
    use BelongsToComplex;

    protected $fillable = [
        'complex_id', 'conversation_id', 'user_id', 'client_id', 'body', 'audience', 'unit_id',
        'attachment_path', 'attachment_name', 'attachment_kind',
        'author_name', 'author_role', 'unit_label',
        'is_hidden', 'hidden_by',
    ];

    protected function casts(): array
    {
        return [
            'is_hidden' => 'boolean',
            'audience' => MessageAudience::class,
        ];
    }

    /**
     * واحدهای گیرنده — فقط برای پیامِ `units`.
     *
     * @return BelongsToMany<Unit, $this>
     */
    public function recipientUnits(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'message_units');
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * کسانی که این پیام را خوانده‌اند (R23b).
     *
     * «خوانده‌نشده» با **نبودِ ردیف** نشان داده می‌شود، نه با پرچمِ false —
     * پس جدول به اندازه‌ی خواندن‌های واقعی رشد می‌کند، نه پیام×کاربر.
     *
     * @return BelongsToMany<User, $this>
     */
    public function readers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'message_reads')
            ->withPivot('read_at');
    }

    /** @return HasOne<MessagePoll, $this> */
    public function poll(): HasOne
    {
        return $this->hasOne(MessagePoll::class);
    }

    public function hasAttachment(): bool
    {
        return $this->attachment_path !== null;
    }

    /**
     * پیام‌هایی که این کاربر حق دیدنشان را دارد (R23؛ گفت‌وگومحور از مرحلهٔ ۶ ·
     * فاز ۱۲).
     *
     * ─── چرا اینجا و نه در کنترلر ──────────────────────────────────────────
     * این تنها جایی است که تصمیم می‌گیرد چه کسی چه پیامی را می‌بیند؛ داشبورد،
     * جست‌وجو، زنگوله و نظرسنجی همه از همین می‌گذرند.
     *
     * ─── دو شاخه، در دوره‌ی گذار ────────────────────────────────────────────
     * پیامِ دارای گفت‌وگو: همان قاعده‌ی `Conversation::visibleTo`. پیامی که هنوز
     * گفت‌وگو ندارد (کدِ قدیمی در فاصله‌ی استقرار نوشته، پیش از
     * `messenger:backfill-conversations`): قاعده‌ی مخاطب‌محورِ قبلی. شاخه‌ی دوم
     * با گامِ Contract حذف می‌شود.
     *
     * 🔴 در هر دو شاخه فقط واحدهای **فعلی**. پیش از این `units()` بود و
     * مستأجرِ رفته رشته‌ی خصوصیِ واحدِ قبلی‌اش با مدیریت را — با پیام‌های
     * مستأجرِ تازه — می‌خواند.
     *
     * @param  Builder<Message>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->role->isAdmin()) {
            return;
        }

        $unitIds = $user->currentUnits()->pluck('units.id')->all();

        $query->where(function (Builder $outer) use ($user, $unitIds): void {
            $outer->where(fn (Builder $q) => $q
                ->whereNotNull('conversation_id')
                ->whereIn('conversation_id', Conversation::query()->visibleTo($user)->select('id')));

            $outer->orWhere(function (Builder $legacy) use ($user, $unitIds): void {
                $legacy->whereNull('conversation_id')->where(function (Builder $inner) use ($user, $unitIds): void {
                    $inner->where('audience', MessageAudience::All->value)
                        ->orWhere('user_id', $user->id);

                    if ($unitIds === []) {
                        return;
                    }

                    $inner
                        ->orWhere(fn (Builder $q) => $q
                            ->where('audience', MessageAudience::Management->value)
                            ->whereIn('unit_id', $unitIds))
                        ->orWhere(fn (Builder $q) => $q
                            ->where('audience', MessageAudience::Units->value)
                            ->whereHas('recipientUnits', fn ($r) => $r->whereIn('units.id', $unitIds)));
                });
            });
        });
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
