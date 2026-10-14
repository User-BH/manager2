<?php

namespace App\Models;

use App\Enums\MessageAudience;
use App\Models\Concerns\BelongsToComplex;
use App\Models\Concerns\KeepsHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int|null $conversation_id
 * @property string|null $client_id
 * @property MessageAudience $audience
 * @property int|null $unit_id
 * @property string|null $attachment_path
 * @property string|null $attachment_name
 * @property string|null $attachment_kind
 * @property int|null $reply_to_id
 * @property Carbon|null $edited_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 */
class Message extends Model
{
    use BelongsToComplex, KeepsHistory;

    protected $fillable = [
        'complex_id', 'conversation_id', 'user_id', 'client_id', 'body', 'audience', 'unit_id',
        'attachment_path', 'attachment_name', 'attachment_kind',
        'author_name', 'author_role', 'unit_label',
        'is_hidden', 'hidden_by', 'reply_to_id', 'edited_at', 'deleted_at',
        // ۱۰/۱۰: حذف‌کننده (نویسنده، مدیر یا ناظر) و منبعِ هدایت
        'deleted_by', 'forwarded_from_id', 'forwarded_author_name',
    ];

    /**
     * پنجره‌ی ویرایشِ پیامِ خود (فاز ۱۴).
     *
     * پیام‌رسانِ ساختمان جای قرار و اعلامِ خرابی است؛ پیامی که ساعت‌ها بعد
     * بی‌صدا عوض شود، سابقه را بی‌اعتبار می‌کند. ربعِ ساعت برای غلطِ تایپی کافی
     * است و نشانه‌ی «ویرایش‌شده» همیشه می‌ماند.
     */
    public const EDIT_WINDOW_MINUTES = 15;

    protected function casts(): array
    {
        return [
            'is_hidden' => 'boolean',
            'edited_at' => 'datetime',
            'deleted_at' => 'datetime',
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

    /**
     * حسابِ نویسنده — فقط برای تصویرِ پروفایلِ **فعلی‌اش** (مرحلهٔ ۱۰ · فاز ۷؛ بندِ ۵). نامِ نویسنده عمداً از
     * `author_name` می‌آید (snapshot ِ تاریخی، بندِ ۲۹)؛ تصویر canonical است و عوض شدنش همه‌جا دیده می‌شود.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
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

    /** @return BelongsTo<self, $this> */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    /** @return HasMany<MessageReaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    public function isEditableBy(User $user): bool
    {
        return $this->user_id === $user->id
            && ! $this->isDeleted()
            && $this->created_at !== null
            && $this->created_at->gt(now()->subMinutes(self::EDIT_WINDOW_MINUTES));
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
        /*
         * 🔴 مدیر هر پیامِ مجتمع را می‌بیند **جز** پیامِ گفت‌وگوهای خصوصیِ دیگران (۱۰/۹؛ بندهای ۳۸ و ۳۹). همین
         * یک‌جا جست‌وجو، پیوست، پنهان‌کردن، واکنش و شمارِ نخوانده را می‌بندد؛ بی‌این، شناسه‌ی پیام کافی بود.
         */
        if ($user->role->isAdmin()) {
            $query->where(fn (Builder $q) => $q->whereNull('conversation_id')
                ->orWhereNotIn('conversation_id', Conversation::query()
                    ->whereIn('type', Conversation::PRIVATE_TYPES)
                    ->whereNot(fn (Builder $own) => $own->where('type', Conversation::SAVED)->where('user_id', $user->id))
                    ->select('id')));

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

        /*
         * 🔴 رشته‌ی خصوصیِ واحد با مدیریت فقط **از شروعِ دوره‌ی خودِ کاربر**
         * (مرحلهٔ ۹ · فاز ۶، F4؛ تصمیمِ ۱۳: «مستأجرِ تازه ≠ مستأجرِ قبلی»).
         *
         * پیش از این مستأجرِ تازه کلِ گفت‌وگوی واحد با مدیریت را — پیام‌های
         * مستأجرِ قبلی — می‌خواند. حالا پیامِ پیش از `start_date` ِ دوره‌ی
         * جاری‌اش روی همان واحد برایش نیست؛ مدیر همه را می‌بیند (بالا).
         *
         * ⚠️ `whereNotNull`ها عمدی‌اند: `NOT (... NULL ...)` در SQL تهی است و
         * ردیفِ بی‌ربط را هم حذف می‌کرد.
         */
        $starts = UnitTenure::query()
            ->where('user_id', $user->id)
            ->where('is_current', true)
            ->whereNotNull('start_date')
            ->get(['unit_id', 'start_date']);

        foreach ($starts as $tenure) {
            $query->whereNot(fn (Builder $q) => $q
                ->whereNotNull('conversation_id')
                ->whereNotNull('created_at')
                ->whereIn('conversation_id', Conversation::query()
                    ->where('type', Conversation::UNIT)
                    ->where('unit_id', $tenure->unit_id)
                    ->select('id'))
                ->where('created_at', '<', $tenure->start_date));

            $query->whereNot(fn (Builder $q) => $q
                ->whereNull('conversation_id')
                ->whereNotNull('unit_id')
                ->whereNotNull('created_at')
                ->where('audience', MessageAudience::Management->value)
                ->where('unit_id', $tenure->unit_id)
                ->where('created_at', '<', $tenure->start_date));
        }

        /*
         * گروه‌های چندواحده‌ی مدیر هم (تصمیمِ کارفرما، مرحلهٔ ۹ · اصلاحاتِ پس از فاز ۶):
         * تاریخچه‌ی کامل **فقط** مالِ گروهِ عمومیِ مجتمع (`CHANNEL`) است — که این‌جا
         * دست نمی‌خورد. در گروهی که ساکن از راهِ واحدش عضوش است، پیامِ پیش از
         * زودترین دوره‌ی او روی واحدهای همان گروه برایش نیست.
         */
        $startByUnit = $starts->pluck('start_date', 'unit_id');
        if ($startByUnit->isNotEmpty()) {
            $groups = DB::table('conversation_units')
                ->join('conversations', 'conversations.id', '=', 'conversation_units.conversation_id')
                ->where('conversations.type', Conversation::GROUP)
                ->whereIn('conversation_units.unit_id', $startByUnit->keys())
                ->get(['conversation_units.conversation_id', 'conversation_units.unit_id']);

            foreach ($groups->groupBy('conversation_id') as $conversationId => $rows) {
                $since = $rows->map(fn ($row) => $startByUnit[$row->unit_id])->min();

                $query->whereNot(fn (Builder $q) => $q
                    ->where('conversation_id', (int) $conversationId)
                    ->whereNotNull('created_at')
                    ->where('created_at', '<', $since));
            }
        }
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
