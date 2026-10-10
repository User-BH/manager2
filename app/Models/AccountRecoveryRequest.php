<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * درخواستِ بازیابیِ حساب با شماره‌ی تازه (مرحلهٔ ۹).
 *
 * ⚠️ `BelongsToComplex` ندارد: مالِ سکوست و فقط ادمینِ کل آن را می‌بیند.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $old_phone
 * @property string $new_phone
 * @property string|null $national_id
 * @property string $explanation
 * @property string $status
 * @property Carbon $new_phone_verified_at
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property string|null $ip_address
 * @property Carbon|null $created_at
 */
class AccountRecoveryRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** اثباتِ مالکیتِ شماره‌ی تازه پس از این مدت کهنه است و تأیید نمی‌شود. */
    public const VALID_DAYS = 7;

    protected $fillable = [
        'user_id', 'old_phone', 'new_phone', 'national_id', 'explanation', 'status',
        'new_phone_verified_at', 'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'new_phone_verified_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function isDecidable(): bool
    {
        return $this->status === self::PENDING
            && $this->new_phone_verified_at->gt(now()->subDays(self::VALID_DAYS));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
