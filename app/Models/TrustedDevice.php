<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * یک دستگاهِ مورداعتمادِ «مرا به خاطر بسپار» متعلق به یک کاربر.
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
