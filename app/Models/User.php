<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Services\Account\MembershipLedger;
use App\Support\PersianName;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $name نامِ نمایشی؛ اگر نام و نام خانوادگی پر باشند، از آن دو ساخته می‌شود
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $phone ستون از اول nullable است؛ از ۱۳/۱ حسابِ آزمایشیِ Preview واقعاً بی شماره است
 * @property UserRole $role
 * @property int|null $complex_id
 * @property bool $is_active
 * @property Carbon|null $last_seen_at آخرین درخواستِ داشبورد — فقط `Presence` می‌نویسد و می‌خواند
 * @property string|null $username شناسه‌ی ورودِ سوپر ادمین و حسابِ آزمایشی (۱۳/۱)؛ نه در `fillable`
 * @property bool $is_demo نشانگرِ سمتِ سرورِ حسابِ آزمایشی (۱۳/۱)؛ فقط seeder ِ Preview می‌نویسد، نه در `fillable`
 * @property string|null $two_factor_secret کلیدِ TOTP ِ سوپر ادمین — رمزنگاری‌شده، پنهان، فقط `AdminTwoFactor`
 * @property string|null $two_factor_pending_secret کلیدِ تازه تا تأیید — رمزنگاری‌شده، پنهان
 * @property Carbon|null $two_factor_pending_at
 * @property string|null $two_factor_recovery_codes HMAC ِ کدهای بازیابی (JSON) — رمزنگاری‌شده، پنهان
 * @property Carbon|null $two_factor_confirmed_at
 * @property int|null $two_factor_last_step آخرین گامِ TOTP ِ پذیرفته‌شده (ضدِ replay)
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

    /** `last_seen_at`: حضور فقط از راهِ `Presence` و فقط به هم‌گروهِ فعال می‌رسد، نه با سریال‌شدنِ حساب */
    protected $hidden = [
        'password', 'remember_token', 'last_seen_at',
        // عاملِ دومِ سوپر ادمین (۲۰۲۶-۱۰-۲۵): هرگز با سریال‌شدنِ حساب بیرون نمی‌رود
        'two_factor_secret', 'two_factor_pending_secret', 'two_factor_recovery_codes', 'two_factor_last_step',
    ];

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
            'last_seen_at' => 'datetime',
            'is_demo' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_pending_secret' => 'encrypted',
            'two_factor_pending_at' => 'datetime',
            'two_factor_recovery_codes' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_step' => 'integer',
        ];
    }

    /**
     * ورود با نام کاربری فقط برای حسابِ آزمایشیِ Preview (پیامِ کارفرما ۲۰۲۶-۱۰-۱۹، بخش ۳). سوپر ادمینِ واقعی از
     * ۲۰۲۶-۱۰-۲۹ (بندِ ۲) با شماره + رمز + Authenticator وارد می‌شود و کاربرِ واقعی با شماره و کدِ پیامکی — هر دو حتی
     * اگر روزی نام کاربری داشته باشند.
     */
    public function signsInByUsername(): bool
    {
        return $this->username !== null && $this->is_demo;
    }

    /**
     * حساب‌های عملیاتی — بی دادهٔ آزمایشی («داشبورد های آزمایشی» بندِ ۴.۲). فهرست، آمار، جست‌وجو و خروجیِ
     * سوپر ادمین به‌طورِ پیش‌فرض از همین می‌خوانند.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeOperational(Builder $query): Builder
    {
        return $query->where('users.is_demo', false);
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
