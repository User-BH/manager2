<?php

namespace App\Models;

use App\Models\Concerns\BelongsToComplex;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * یک گفت‌وگوی پیام‌رسان (مرحلهٔ ۶ · فاز ۱۲).
 *
 * چهار نوع، و قاعده‌ی دیدنِ هرکدام در یک جمله:
 *
 *   channel  کانالِ کلِ مجتمع — همه می‌بینند، فقط مدیریت می‌نویسد
 *   unit     رشته‌ی خصوصیِ یک واحد با مدیریت — ساکنانِ **فعلیِ** واحد و مدیریت
 *   user     رشته‌ی خصوصیِ ساکنِ بی‌واحد با مدیریت — همان ساکن و مدیریت
 *   group    واحدهای انتخابیِ مدیر — ساکنانِ فعلیِ آن واحدها و مدیریت؛
 *            نوشتنِ اعضا با `members_can_post`
 *
 * 🔴 «فعلی» کلیدی است: پیش از این دید با `units()` بود که واحدهای **قبلیِ**
 * کاربر را هم می‌آورد — مستأجرِ رفته گفت‌وگوی خصوصیِ مستأجرِ تازه با مدیریت را
 * می‌خواند. حالا `currentUnits()`.
 *
 * @property int $id
 * @property int $complex_id
 * @property string $type
 * @property string $key
 * @property string|null $title
 * @property int|null $unit_id
 * @property int|null $user_id
 * @property bool $members_can_post
 * @property int|null $created_by
 * @property int $last_message_id
 * @property Carbon|null $last_message_at
 * @property int|null $pinned_message_id
 */
class Conversation extends Model
{
    use BelongsToComplex;

    public const CHANNEL = 'channel';

    public const UNIT = 'unit';

    public const USER = 'user';

    public const GROUP = 'group';

    protected $fillable = [
        'complex_id', 'type', 'key', 'title', 'unit_id', 'user_id', 'members_can_post', 'created_by', 'pinned_message_id',
        'last_message_id', 'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'members_can_post' => 'boolean',
            'last_message_id' => 'integer',
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * گفت‌وگوهایی که این کاربر حق دیدنشان را دارد — **تنها** جای این تصمیم.
     *
     * @param  Builder<Conversation>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->role->isAdmin()) {
            return;
        }

        $unitIds = $user->currentUnits()->pluck('units.id')->all();

        $query->where(function (Builder $inner) use ($user, $unitIds): void {
            $inner->where('type', self::CHANNEL)
                ->orWhere(fn (Builder $q) => $q->where('type', self::USER)->where('user_id', $user->id));

            if ($unitIds === []) {
                return;
            }

            $inner->orWhere(fn (Builder $q) => $q->where('type', self::UNIT)->whereIn('unit_id', $unitIds))
                ->orWhere(fn (Builder $q) => $q->where('type', self::GROUP)
                    ->whereHas('units', fn (Builder $u) => $u->whereIn('units.id', $unitIds)));
        });
    }

    public function isVisibleTo(User $user): bool
    {
        return static::query()->whereKey($this->id)->visibleTo($user)->exists();
    }

    /**
     * آیا این کاربر در این گفت‌وگو **می‌نویسد**؟ (دیدن جداست.)
     *
     * کانال فقط برای مدیریت؛ گروه به انتخابِ مدیر؛ رشته‌های خصوصی برای هر دو
     * طرف. قیدهای مجتمع و کاربر (`messenger_enabled`، `can_message`) جدا در
     * `MessageSender` سنجیده می‌شوند.
     */
    public function allowsPostingBy(User $user): bool
    {
        if ($user->role->isAdmin()) {
            return true;
        }

        return match ($this->type) {
            self::CHANNEL => false,
            self::GROUP => $this->members_can_post,
            default => true,
        };
    }

    /** نامِ گفت‌وگو از دیدِ همین بیننده. */
    public function titleFor(User $user): string
    {
        $admin = $user->role->isAdmin();

        return match ($this->type) {
            self::CHANNEL => 'همه‌ی اهالی',
            // واحدِ حذف‌شده (nullOnDelete): `??` روی ویژگیِ تهی بی‌خطاست
            self::UNIT => $admin ? 'واحد '.($this->unit->unit_number ?? '؟') : 'مدیریتِ ساختمان',
            self::USER => $admin ? (($this->user->name ?? '') ?: 'ساکنِ بی‌واحد') : 'مدیریتِ ساختمان',
            default => $this->title ?: 'گروه',
        };
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** @return BelongsToMany<Unit, $this> */
    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'conversation_units');
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
