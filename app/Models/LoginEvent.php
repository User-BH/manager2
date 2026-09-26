<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * یک ردیف از تاریخچه‌ی ورودِ یک کاربر (مرحلهٔ ۵ · فاز ۱۰).
 *
 * ⚠️ مالِ کاربر است نه مجتمع، پس `BelongsToComplex` ندارد: فقط خودِ صاحبِ
 * حساب از راهِ `/profile/login-history` آن را می‌بیند.
 */
class LoginEvent extends Model
{
    /** ورودِ موفق با رمز (و کدِ پیامکی اگر روشن باشد). */
    public const SUCCESS = 'success';

    /** ورودِ خودکار با دستگاهِ مورداعتماد. */
    public const TRUSTED_DEVICE = 'trusted_device';

    /** ورود پس از بازیابیِ رمز با کدِ پیامکی. */
    public const PASSWORD_RESET = 'password_reset';

    public const FAILED_PASSWORD = 'failed_password';

    public const FAILED_OTP = 'failed_otp';

    /** رمزِ نادرست در تأییدِ دوباره پیش از عملیاتِ حساس. */
    public const FAILED_REAUTH = 'failed_reauth';

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'event', 'ip_address', 'user_agent', 'device_hash'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFailure(): bool
    {
        return str_starts_with($this->event, 'failed_');
    }
}
