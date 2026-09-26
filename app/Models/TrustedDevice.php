<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * یک دستگاهِ مورداعتمادِ «مرا به خاطر بسپار» متعلق به یک کاربر.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string|null $label
 * @property Carbon|null $last_used_at
 * @property Carbon $expires_at
 */
class TrustedDevice extends Model
{
    protected $fillable = ['user_id', 'token_hash', 'label', 'last_used_at', 'expires_at'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** فقط دستگاه‌هایی که هنوز منقضی نشده‌اند. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }
}
