<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'complex_id', 'name', 'email', 'phone', 'national_id',
        'birth_date', 'emergency_phone', 'address', 'bio',
        'role', 'password', 'is_active', 'can_message', 'terms_accepted_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'birth_date' => 'date',
            'is_active' => 'boolean',
            'can_message' => 'boolean',
            'terms_accepted_at' => 'datetime',
        ];
    }

    public function complex(): BelongsTo
    {
        return $this->belongsTo(Complex::class);
    }

    public function trustedDevices(): HasMany
    {
        return $this->hasMany(TrustedDevice::class);
    }

    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class)
            ->withPivot(['relation', 'share_percent', 'start_date', 'end_date', 'is_current'])
            ->withTimestamps();
    }

    public function currentUnits(): BelongsToMany
    {
        return $this->units()->wherePivot('is_current', true);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    public function isComplexAdmin(): bool
    {
        return $this->role === UserRole::ComplexAdmin;
    }

    public function isAdmin(): bool
    {
        return $this->role?->isAdmin() ?? false;
    }
}
