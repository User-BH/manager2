<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\BelongsToComplex;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * عضویتِ یک حساب در یک مجتمع (مرحلهٔ ۱۰ · فاز ۲؛ بندِ ۱۱ ِ فایلِ معماری).
 *
 * چرخه‌ی عمر: `active` ← `ended`. «در انتظار»، «ردشده» و «ابطال‌شده» مالِ دعوت و
 * درخواست‌اند (`ComplexInvitation`)؛ عضویت فقط وقتی ساخته می‌شود که واقعاً برقرار شود.
 *
 * 🔴 تنها نویسنده `MembershipLedger` است — هیچ فیلدِ قابلِ پرشدن ندارد. حساب
 * (`users.complex_id` و `users.role`) هم فقط از همان‌جا عوض می‌شود، تا این دو
 * هرگز از هم جدا نشوند (`memberships:check`).
 *
 * @property int $id
 * @property int $user_id
 * @property int $complex_id
 * @property UserRole $role
 * @property string $status
 * @property string $source
 * @property int|null $invitation_id
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property int|null $ended_by
 * @property string|null $end_reason
 * @property string|null $active_key
 * @property Carbon|null $suspended_at
 * @property int|null $suspended_by
 */
class ComplexMembership extends Model
{
    use BelongsToComplex;

    public const ACTIVE = 'active';

    /**
     * معلق به دستِ مدیرِ همان مجتمع (مرحلهٔ ۱۰ · فاز ۶): دسترسی به همین مجتمع بسته، عضویت زنده — برگشت‌پذیر و
     * هنوز «عضو» برای دعوت و درخواست. مجتمع‌های دیگرِ همان حساب دست نمی‌خورند.
     */
    public const SUSPENDED = 'suspended';

    public const ENDED = 'ended';

    /** عضویتِ «زنده»: فعال یا معلق — همان که `active_key` ِ یکتا دارد */
    public const LIVE = [self::ACTIVE, self::SUSPENDED];

    /** از کجا برقرار شد */
    public const SOURCE_INVITATION = 'invitation';

    public const SOURCE_JOIN_REQUEST = 'join_request';

    public const SOURCE_PURCHASE = 'purchase';

    /** حسابی که از ابتدا در مجتمع ساخته شد (دادهٔ نمونه، ابزارِ آزمون) */
    public const SOURCE_DIRECT = 'direct';

    /** مهاجرتِ ۱۰/۲ از `users.complex_id` */
    public const SOURCE_BACKFILL = 'backfill';

    /** چرا پایان یافت */
    public const END_REMOVED = 'removed';

    /** به ادمینِ کل ارتقا یافت — نقشِ سکو، نه مجتمع */
    public const END_PROMOTED = 'promoted';

    /** نقش‌هایی که عضویت دارند */
    public const ROLES = [UserRole::Owner, UserRole::Tenant, UserRole::ComplexAdmin];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    public static function activeKey(int $userId, int $complexId): string
    {
        return $userId.':'.$complexId;
    }

    /**
     * @param  Builder<ComplexMembership>  $query
     * @return Builder<ComplexMembership>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }

    /**
     * @param  Builder<ComplexMembership>  $query
     * @return Builder<ComplexMembership>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', self::LIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Complex, $this> */
    public function complex(): BelongsTo
    {
        return $this->belongsTo(Complex::class);
    }

    /** @return BelongsTo<ComplexInvitation, $this> */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(ComplexInvitation::class);
    }
}
