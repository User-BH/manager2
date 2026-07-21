<?php

namespace App\Models;

use App\Enums\AnnouncementAudience;
use App\Enums\UserRole;
use App\Models\Concerns\BelongsToComplex;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Announcement extends Model
{
    use BelongsToComplex;

    protected $fillable = [
        'complex_id', 'title', 'body', 'audience',
        'is_active', 'is_pinned', 'published_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'audience' => AnnouncementAudience::class,
            'is_active' => 'boolean',
            'is_pinned' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(AnnouncementRead::class);
    }

    /**
     * اطلاعیه‌هایی که این کاربر حق دیدنشان را دارد.
     *
     * هم صفحه‌ی اطلاعیه‌ها و هم شمارنده‌ی زنگوله از همین قید استفاده می‌کنند
     * تا شماره‌ی روی زنگوله هرگز اطلاعیه‌ای را نشمارد که کاربر در فهرست
     * نمی‌بیند و در نتیجه هیچ‌وقت نمی‌تواند صفرش کند.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where('is_active', true)->whereIn('audience', [
            AnnouncementAudience::All->value,
            $user->role === UserRole::Owner
                ? AnnouncementAudience::Owners->value
                : AnnouncementAudience::Tenants->value,
        ]);
    }
}
