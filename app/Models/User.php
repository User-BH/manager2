<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Services\Account\MembershipLedger;
use App\Support\PersianName;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use LogicException;

/**
 * @property int $id
 * @property string $name نامِ نمایشی؛ اگر نام و نام خانوادگی پر باشند، از آن دو ساخته می‌شود
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string $phone
 * @property UserRole $role
 * @property int|null $complex_id
 * @property bool $is_active
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'complex_id', 'name', 'first_name', 'last_name', 'email', 'phone', 'national_id',
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

    /** @return BelongsTo<Complex, $this> */
    public function complex(): BelongsTo
    {
        return $this->belongsTo(Complex::class);
    }

    /** @return HasMany<TrustedDevice, $this> */
    public function trustedDevices(): HasMany
    {
        return $this->hasMany(TrustedDevice::class);
    }

    /** @return BelongsToMany<Unit, $this> */
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

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * پرداخت‌های این کاربر (R29).
     *
     * تا امروز فقط از سمتِ `Payment` خوانده می‌شد؛ برای سنجشِ «کاربرِ
     * درگیر» در آمارِ پلتفرم، جهتِ برعکس هم لازم شد.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<ServiceRequest, $this> */
    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class);
    }

    /**
     * `name` از نام و نام خانوادگی ساخته می‌شود (مرحلهٔ ۹ · فاز ۳).
     *
     * ⚠️ هر جا هر دو بخش پر باشند، `name` ِ ورودی نادیده گرفته می‌شود؛ وگرنه
     * فرمی که هنوز فقط `name` می‌فرستد دو منبعِ ناسازگار می‌ساخت. حسابی که هنوز
     * بخش‌ها را ندارد (قبلی) همان `name` ِ خودش را نگه می‌دارد.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if (filled($user->first_name) && filled($user->last_name)) {
                $user->name = PersianName::full($user->first_name, $user->last_name);
            }
        });

        /*
         * 🔴 عضویت و زمینه‌ی فعالِ حساب از هم جدا نمی‌شوند (مرحلهٔ ۱۰ · فاز ۲).
         * `complex_id` و `role` ِ حسابِ موجود فقط از `MembershipLedger` عوض می‌شوند؛
         * هر مسیرِ دیگری (کنترلر، فرمان، آزمون) خطا می‌گیرد، نه ناهم‌خوانیِ بی‌صدا.
         */
        static::updating(function (User $user): void {
            if (($user->isDirty('complex_id') || $user->isDirty('role')) && ! MembershipLedger::isWriting()) {
                throw new LogicException('مجتمع و نقشِ حساب فقط از MembershipLedger عوض می‌شوند (عضویت، پایان، تغییرِ نقش).');
            }
        });

        // حسابی که از ابتدا درونِ مجتمع ساخته شد، عضوِ همان مجتمع است
        static::created(function (User $user): void {
            app(MembershipLedger::class)->recordCreated($user);
        });
    }

    /** نام و نام خانوادگی هنوز جدا ثبت نشده‌اند (حسابِ پیش از مرحلهٔ ۹). */
    public function needsNameCompletion(): bool
    {
        return blank($this->first_name) || blank($this->last_name);
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
