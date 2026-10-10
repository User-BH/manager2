<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * تغییرِ شماره‌ای که هنوز با کدِ پیامکی تأیید نشده (مرحلهٔ ۹).
 *
 * ⚠️ `BelongsToComplex` ندارد: مالِ حساب است، نه مجتمع — حسابِ اولیه هم شماره‌اش
 * را عوض می‌کند. همیشه با `user_id = خودش` خوانده می‌شود.
 *
 * @property int $id
 * @property int $user_id
 * @property string $new_phone
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhoneChangeRequest extends Model
{
    /** عمرِ درخواست؛ کدِ پیامکی خودش کوتاه‌تر است (`OtpService::TTL_SECONDS`). */
    public const TTL_MINUTES = 15;

    protected $fillable = ['user_id', 'new_phone', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
