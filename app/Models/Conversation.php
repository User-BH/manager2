<?php

namespace App\Models;

use App\Models\Concerns\BelongsToComplex;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * یک گفت‌وگوی پیام‌رسان (مرحلهٔ ۶ · فاز ۱۲؛ ساختارِ مرحلهٔ ۱۰ · فاز ۹).
 *
 * شش نوع، و قاعده‌ی دیدنِ هرکدام در یک جمله:
 *
 *   channel  گروهِ اصلیِ مجتمع («گروهِ {نامِ مجتمع}»، بندهای ۳۲ و ۳۳) — همه‌ی اعضای فعال؛ اعضا می‌نویسند مگر مدیر
 *            نوشتن را برای مدیریت نگه دارد (`members_can_post`)
 *   user     گفت‌وگوی خصوصیِ **یک عضو** با مدیریت (بندِ ۳۷) — همان عضو و مدیریت؛ مالک و مستأجرِ یک واحد هر کدام
 *            گفت‌وگوی خودشان را دارند
 *   pair     «مالک ↔ مستأجر»ِ یک واحد (بندِ ۳۸) — `user_id` مالک و `peer_id` مستأجر؛ **مدیر نمی‌بیند**. هر طرف تا
 *            وقتی خودش ساکنِ همان واحد است می‌بیند؛ نوشتن فقط وقتی هر دو هنوز ساکن‌اند
 *   saved    «پیام‌های ذخیره‌شده» (بندِ ۳۹) — فقط `user_id`؛ **هیچ کس دیگر، حتی مدیر**. در هر مجتمع یکی (تصمیمِ
 *            کارفرما، ۱۰/۹: پیام‌رسان، پیوست و پشتیبان درونِ مرزِ مجتمع‌اند)
 *   group    واحدهای انتخابیِ مدیر — ساکنانِ فعلیِ آن واحدها و مدیریت؛ نوشتنِ اعضا با `members_can_post`
 *   unit     بایگانیِ رشته‌ی مشترکِ واحد با مدیریت از پیش از ۱۰/۹ — همان کسانی که پیش‌تر می‌دیدند (ساکنانِ **فعلیِ**
 *            واحد، از شروعِ دوره‌شان) و مدیریت؛ **فقط خواندنی** (تصمیمِ کارفرما)
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
 * @property int|null $peer_id
 * @property bool $members_can_post
 * @property int|null $created_by
 * @property int $last_message_id
 * @property Carbon|null $last_message_at
 */
class Conversation extends Model
{
    use BelongsToComplex;

    public const CHANNEL = 'channel';

    public const UNIT = 'unit';

    public const USER = 'user';

    public const GROUP = 'group';

    public const PAIR = 'pair';

    public const SAVED = 'saved';

    /** گفت‌وگوهایی که مدیر در آن‌ها طرف نیست و نمی‌بیند — مگر «ذخیره‌شده»ِ خودش. */
    public const PRIVATE_TYPES = [self::PAIR, self::SAVED];

    protected $fillable = [
        'complex_id', 'type', 'key', 'title', 'unit_id', 'user_id', 'peer_id', 'members_can_post', 'created_by',
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
        /*
         * 🔴 مدیر همه را می‌بیند **جز** گفت‌وگوهای خصوصیِ دیگران (۱۰/۹): «مالک ↔ مستأجر» میانِ دو ساکن است و
         * «ذخیره‌شده» مالِ یک نفر — بندِ ۳۹: «هیچ user دیگری حتی Manager».
         */
        if ($user->role->isAdmin()) {
            $query->where(fn (Builder $q) => $q->whereNotIn('type', self::PRIVATE_TYPES)
                ->orWhere(fn (Builder $own) => $own->where('type', self::SAVED)->where('user_id', $user->id)));

            return;
        }

        $current = $user->currentUnits()->get(['units.id']);
        $unitIds = $current->pluck('id')->all();
        $asOwner = $current->filter(fn (Model $u) => $u->getRelationValue('pivot')?->relation === 'owner')->pluck('id')->all();
        $asTenant = $current->filter(fn (Model $u) => $u->getRelationValue('pivot')?->relation === 'tenant')->pluck('id')->all();

        $query->where(function (Builder $inner) use ($user, $unitIds, $asOwner, $asTenant): void {
            $inner->where('type', self::CHANNEL)
                ->orWhere(fn (Builder $q) => $q->whereIn('type', [self::USER, self::SAVED])->where('user_id', $user->id));

            // «مالک ↔ مستأجر»: هر طرف تا وقتی خودش ساکنِ همان واحد با همان نسبت است
            if ($asOwner !== []) {
                $inner->orWhere(fn (Builder $q) => $q->where('type', self::PAIR)->where('user_id', $user->id)->whereIn('unit_id', $asOwner));
            }
            if ($asTenant !== []) {
                $inner->orWhere(fn (Builder $q) => $q->where('type', self::PAIR)->where('peer_id', $user->id)->whereIn('unit_id', $asTenant));
            }

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
        return match ($this->type) {
            self::SAVED => $this->user_id === $user->id,
            // هر دو هنوز ساکنِ همان واحد؛ با رفتنِ یکی، تاریخچه برای ماندگار فقط خواندنی است
            self::PAIR => in_array($user->id, [$this->user_id, $this->peer_id], true) && $this->bothStillLiveHere(),
            // بایگانیِ پیش از ۱۰/۹: هیچ کس، حتی مدیریت — نوشتنِ تازه در گفت‌وگوی خصوصیِ هر عضو است
            self::UNIT => false,
            self::CHANNEL, self::GROUP => $user->role->isAdmin() || $this->members_can_post,
            default => true,
        };
    }

    /** چرا این بیننده این‌جا نمی‌نویسد — برای پیامِ خطا و کادرِ نوشتن. */
    public function readOnlyReason(): string
    {
        return match ($this->type) {
            self::UNIT => 'این گفت‌وگوی پیشینِ واحد است و بایگانی شده؛ برای نوشتن به «مدیریتِ ساختمان» بروید.',
            self::PAIR => 'طرفِ دیگرِ این گفت‌وگو دیگر ساکنِ این واحد نیست؛ تاریخچه فقط خواندنی است.',
            default => 'در این گفت‌وگو فقط مدیریت پیام می‌فرستد.',
        };
    }

    private function bothStillLiveHere(): bool
    {
        if ($this->user_id === null || $this->peer_id === null || $this->unit_id === null) {
            return false;
        }

        return DB::table('unit_user')
            ->where('unit_id', $this->unit_id)
            ->where('is_current', true)
            ->where(fn ($q) => $q
                ->where(fn ($o) => $o->where('user_id', $this->user_id)->where('relation', 'owner'))
                ->orWhere(fn ($t) => $t->where('user_id', $this->peer_id)->where('relation', 'tenant')))
            ->distinct()
            ->count('user_id') === 2;
    }

    /** نامِ گفت‌وگو از دیدِ همین بیننده. */
    public function titleFor(User $user): string
    {
        $admin = $user->role->isAdmin();

        return match ($this->type) {
            // از نامِ مجتمع، هر بار — تغییرِ نامِ مجتمع همان لحظه نامِ گروه است و نسخه‌ی دومی ذخیره نمی‌شود (بندِ ۳۳)
            self::CHANNEL => 'گروهِ '.($this->complex->name ?? 'مجتمع'),
            // واحدِ حذف‌شده (nullOnDelete): `??` روی ویژگیِ تهی بی‌خطاست
            self::UNIT => $admin ? 'بایگانیِ واحد '.($this->unit->unit_number ?? '؟') : 'بایگانیِ گفت‌وگوی واحد با مدیریت',
            self::USER => $admin ? (($this->user->name ?? '') ?: 'عضو') : 'مدیریتِ ساختمان',
            self::PAIR => $this->user_id === $user->id
                ? (($this->peer->name ?? '') ?: 'مستأجر').' · مستأجر'
                : (($this->user->name ?? '') ?: 'مالک').' · مالک',
            self::SAVED => 'پیام‌های ذخیره‌شده',
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

    /**
     * مستأجرِ گفت‌وگوی «مالک ↔ مستأجر».
     *
     * @return BelongsTo<User, $this>
     */
    public function peer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'peer_id');
    }
}
