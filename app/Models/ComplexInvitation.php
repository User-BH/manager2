<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * دعوتِ یک کاربرِ موجود به یک مجتمع (R21).
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
 * @property int $user_id
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

    /** مدیر فرستاده؛ کاربر تایید می‌کند. */
    public const INVITE = 'invite';

    /** کاربر فرستاده؛ مدیر تایید می‌کند. */
    public const REQUEST = 'request';

    protected $fillable = [
        'complex_id', 'user_id', 'unit_id', 'role',
        'direction', 'invited_by', 'status', 'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'responded_at' => 'datetime',
        ];
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
