<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * دعوت به یک مجتمع (R21) — و از مرحلهٔ ۹ · فاز ۶، **دعوتِ امن به یک شماره**.
 *
 * ─── قاعده‌های دعوتِ مدیر (`direction = invite`) ───────────────────────────
 * • به **شماره** بسته است (`phone`)، نه به حساب؛ گیرنده شاید هنوز حساب ندارد.
 * • توکنِ ۲۵۶ بیتی با `random_bytes`؛ فقط SHA-256 ِ آن ذخیره می‌شود و خودش
 *   یک بار به دعوت‌کننده نشان داده می‌شود (`issue`).
 * • انقضا (`TTL_DAYS`)، ابطال، و یک‌بارمصرف: فقط «زنده» (`live`) پذیرفتنی است
 *   و پاسخ زیرِ قفلِ ردیف ثبت می‌شود.
 * • **دعوت به‌تنهایی دسترسی نمی‌دهد:** پذیرش فقط از حسابِ همان شماره، با کدِ
 *   OTP ِ تازه، و فقط برای حسابِ اولیه (`Membership::accept`).
 *
 * ⚠️ این مدل عمداً `BelongsToComplex` **ندارد**.
 *
 * دلیلش: دعوت را کاربری می‌خواند که هنوز به هیچ مجتمعی وصل نیست، و برای او
 * دامنه‌ی مستأجر «هیچ‌چیز» است (R21). اگر این مدل هم دامنه می‌داشت، گیرنده
 * هرگز دعوتِ خودش را نمی‌دید. به‌جای دامنه، هر کوئری صریحاً به `user_id` یا
 * `complex_id` محدود می‌شود — که در کنترلر انجام می‌شود و تست دارد.
 *
 * @property int $id
 * @property int $complex_id
 * @property int|null $user_id
 * @property string|null $phone
 * @property string|null $token_hash
 * @property Carbon|null $expires_at
 * @property string|null $live_key
 * @property int|null $unit_id
 * @property UserRole $role
 * @property string $direction
 * @property int|null $invited_by
 * @property string $status
 * @property Carbon|null $responded_at
 * @property Carbon|null $created_at
 */
class ComplexInvitation extends Model
{
    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    /** دعوت‌کننده پس گرفت. */
    public const REVOKED = 'revoked';

    /** عمرِ دعوت؛ پس از آن پذیرفتنی نیست و دعوتِ تازه لازم است. */
    public const TTL_DAYS = 7;

    /** مدیر فرستاده؛ کاربر تایید می‌کند. */
    public const INVITE = 'invite';

    /** کاربر فرستاده؛ مدیر تایید می‌کند. */
    public const REQUEST = 'request';

    /*
     * ⚠️ `token_hash`، `live_key` و `expires_at` fillable نیستند: فقط `issue`
     * و `close` آن‌ها را می‌نویسند، تا هیچ ورودیِ درخواستی توکن یا عمرِ دعوت را
     * تعیین نکند.
     */
    protected $fillable = [
        'complex_id', 'user_id', 'phone', 'unit_id', 'role',
        'direction', 'invited_by', 'status', 'responded_at',
    ];

    protected $hidden = ['token_hash', 'live_key'];

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'responded_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * ساختِ دعوتِ تازه؛ توکنِ خام **فقط** همین‌جا برمی‌گردد.
     *
     * `live_key` یکتاست: دعوتِ زنده‌ی دوم برای همان (مجتمع، شماره) در خودِ
     * پایگاه‌داده رد می‌شود — حتی با دو درخواستِ هم‌زمان.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: self, 1: string}
     */
    public static function issue(array $attributes): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $invitation = new self($attributes + ['direction' => self::INVITE, 'status' => self::PENDING]);
        $invitation->forceFill([
            'token_hash' => self::hashToken($token),
            'expires_at' => now()->addDays(self::TTL_DAYS),
            'live_key' => self::liveKey($invitation->complex_id, (string) $invitation->phone),
        ])->save();

        return [$invitation, $token];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** دعوتِ زنده با این توکن — یا null (نامعتبر، مصرف‌شده، باطل، منقضی). */
    public static function findLiveByToken(string $token): ?self
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }

        return self::query()->invites()->live()->where('token_hash', self::hashToken($token))->first();
    }

    public static function liveKey(int $complexId, string $phone): string
    {
        return 'invite:'.$complexId.':'.$phone;
    }

    /** پایانِ زندگیِ دعوت: وضعیتِ نهایی، و آزاد شدنِ جای «دعوتِ زنده». */
    public function close(string $status, ?int $userId = null): void
    {
        $this->forceFill(array_filter([
            'status' => $status,
            'responded_at' => now(),
            'live_key' => null,
            'user_id' => $userId,
        ], fn ($value, $key) => $value !== null || $key === 'live_key', ARRAY_FILTER_USE_BOTH))->save();
    }

    /**
     * 🔴 بی انقضا یعنی منقضی (۱۲/۳، بندِ ۱۵). ردیفِ پیش از ۹/۶ نه توکن داشت نه انقضا و تا پیش از این برای همیشه
     * «زنده» شمرده می‌شد؛ قاعده‌ی خودِ مهاجرت («دعوتِ بی‌انقضا پذیرفته نیست»، F7) حالا در کد هم هست.
     */
    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    /** در انتظار و منقضی‌نشده. */
    public function isLive(): bool
    {
        return $this->status === self::PENDING && ! $this->isExpired();
    }

    /** @param  Builder<ComplexInvitation>  $query */
    public function scopeLive(Builder $query): void
    {
        // همان `isExpired`: بی انقضا زنده نیست — `NULL > now` در SQL درست نیست (پیش از ۱۲/۳ شاخه‌ی `orWhereNull` بود)
        $query->where('status', self::PENDING)->where('expires_at', '>', now());
    }

    /** @param  Builder<ComplexInvitation>  $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', self::PENDING);
    }

    /** دعوت‌هایی که مدیر فرستاده و کاربر باید پاسخ بدهد. */
    public function scopeInvites(Builder $query): void
    {
        $query->where('direction', self::INVITE);
    }

    /** درخواست‌هایی که کاربر فرستاده و مدیر باید پاسخ بدهد. */
    public function scopeRequests(Builder $query): void
    {
        $query->where('direction', self::REQUEST);
    }

    /** @return BelongsTo<Complex, $this> */
    public function complex(): BelongsTo
    {
        return $this->belongsTo(Complex::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
