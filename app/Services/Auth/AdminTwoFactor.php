<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * عاملِ دومِ سوپر ادمین: شماره + رمز + کدِ برنامه‌ی Authenticator (TOTP) + کدهای بازیابیِ یک‌بارمصرف (دستورِ
 * یکپارچه‌ی ۲۰۲۶-۱۰-۲۵، بخشِ ۵؛ شناسه از ۲۰۲۶-۱۰-۲۹ شماره‌ی موبایلِ هر مدیر است، نه نام کاربری).
 *
 * قاعده‌ها:
 *
 *   ① عاملِ دوم فقط مالِ سوپر ادمین است؛ کاربرِ عادی همان شماره و کدِ پیامکی را دارد.
 *   ② سوپر ادمینی که TOTP فعال کرده **همیشه** کدِ برنامه می‌خواهد — با هر شناسه (نام کاربری یا شماره)، با کلیدِ
 *      سراسریِ `otp_disabled` یا بی آن. خاموش‌بودنِ پیامک عاملِ دومِ او را خاموش نمی‌کند.
 *   ③ روی Production سوپر ادمینِ بی TOTP وارد نمی‌شود — نه با پیامک، نه فقط با رمز (۲۰۲۶-۱۰-۲۹، بندِ ۲)؛ راهِ
 *      راه‌اندازی فرمانِ `admin:two-factor <شماره>` روی سرور است. نشستی که با این حال بی TOTP بماند (مثلاً پس از
 *      `--reset`) جز مسیرهای شخصیِ خودش به جایی نمی‌رسد (`EnsureAdminTwoFactor`).
 *   ④ کلید و کدهای بازیابی رمزنگاری‌شده‌اند؛ از کدها فقط HMAC (با کلیدِ برنامه) نگه داشته می‌شود و خودِ کدها فقط یک
 *      بار، همان لحظه‌ی ساخت، به صاحبشان نشان داده می‌شوند.
 *   ⑤ هر کدِ بازیابی یک بار: مصرف زیرِ قفلِ ردیف تا دو درخواستِ هم‌زمان با یک کد هر دو نپذیرند.
 *   ⑥ هر رویداد — فعال‌سازی، تعویض، ساختِ دوباره‌ی کدها، مصرفِ کدِ بازیابی، بازنشانی — در audit log.
 */
class AdminTwoFactor
{
    public const RECOVERY_CODES = 10;

    /** مهلتِ تأییدِ کلیدِ تازه */
    public const PENDING_MINUTES = 10;

    public const ISSUER = 'Sakena';

    public function enabled(User $user): bool
    {
        return $user->isSuperAdmin() && $user->two_factor_confirmed_at !== null && filled($user->two_factor_secret);
    }

    /**
     * سوپر ادمینی که هنوز TOTP ندارد و این‌جا باید داشته باشد (Production). حسابِ آزمایشیِ Preview هرگز روی Production
     * نیست، پس این قاعده به آن نمی‌رسد.
     */
    public function setupRequired(User $user): bool
    {
        return $user->isSuperAdmin() && ! $this->enabled($user) && app()->environment('production');
    }

    /**
     * کلیدِ تازه در انتظارِ تأیید. اگر TOTP از قبل فعال است، کلیدِ فعال تا تأییدِ این یکی معتبر می‌ماند (تعویضِ امن).
     *
     * @return array{secret: string, uri: string}
     */
    public function begin(User $user): array
    {
        $secret = Totp::generateSecret();

        $user->forceFill([
            'two_factor_pending_secret' => $secret,
            'two_factor_pending_at' => now(),
        ])->save();

        return ['secret' => $secret, 'uri' => Totp::uri($secret, self::ISSUER, $this->accountLabel($user))];
    }

    /**
     * تأییدِ کلیدِ تازه با یک کدِ درست از همان کلید ← فعال (یا جایگزینِ قبلی)، با ده کدِ بازیابیِ تازه.
     *
     * @return list<string>|null کدهای بازیابی — فقط همین یک بار؛ null یعنی کد یا مهلت نادرست است
     */
    public function confirm(User $user, string $code): ?array
    {
        $pending = $user->two_factor_pending_secret;
        $at = $user->two_factor_pending_at;

        if (! filled($pending) || $at === null || $at->lt(now()->subMinutes(self::PENDING_MINUTES))) {
            return null;
        }

        $step = Totp::verify($pending, $code);
        if ($step === null) {
            return null;
        }

        $rotated = $this->enabled($user);
        $codes = $this->freshCodes();

        $user->forceFill([
            'two_factor_secret' => $pending,
            'two_factor_pending_secret' => null,
            'two_factor_pending_at' => null,
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $step,
            'two_factor_recovery_codes' => $this->digests($codes),
        ])->save();

        Audit::log(
            $rotated ? 'two_factor.rotated' : 'two_factor.enabled',
            $rotated ? 'تعویضِ برنامه‌ی Authenticator ِ سوپر ادمین' : 'فعال‌سازیِ ورودِ دومرحله‌ای با Authenticator',
            $user,
            [],
            null,
        );

        return $codes;
    }

    /**
     * کدِ فعلیِ برنامه، با جلوگیری از استفاده‌ی دوباره. پذیرش زیرِ قفلِ ردیف: دو درخواستِ هم‌زمان با یک کد هر دو
     * پذیرفته نمی‌شوند.
     */
    public function verifyCode(User $user, string $code): bool
    {
        if (! $this->enabled($user)) {
            return false;
        }

        return DB::transaction(function () use ($user, $code): bool {
            /** @var User $locked */
            $locked = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($user->id);
            $step = Totp::verify((string) $locked->two_factor_secret, $code, $locked->two_factor_last_step);

            if ($step === null) {
                return false;
            }

            $locked->forceFill(['two_factor_last_step' => $step])->save();
            $user->setRawAttributes($locked->getAttributes(), true);

            return true;
        }, attempts: 3);
    }

    /**
     * مصرفِ یک کدِ بازیابی؛ کدِ مصرف‌شده از فهرست بیرون می‌رود و دیگر کار نمی‌کند.
     *
     * @return int|null شمارِ کدهای باقی‌مانده، یا null اگر کد معتبر نیست
     */
    public function useRecoveryCode(User $user, string $code): ?int
    {
        if (! $this->enabled($user)) {
            return null;
        }

        $digest = $this->digest($code);

        $remaining = DB::transaction(function () use ($user, $digest): ?int {
            /** @var User $locked */
            $locked = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($user->id);
            $codes = $this->storedDigests($locked);

            $found = null;
            foreach ($codes as $i => $stored) {
                if (hash_equals($stored, $digest)) {
                    $found = $i;
                }
            }
            if ($found === null) {
                return null;
            }

            unset($codes[$found]);
            $locked->forceFill(['two_factor_recovery_codes' => json_encode(array_values($codes))])->save();
            $user->setRawAttributes($locked->getAttributes(), true);

            return count($codes);
        }, attempts: 3);

        if ($remaining !== null) {
            Audit::log('two_factor.recovery_code_used', 'ورودِ سوپر ادمین با کدِ بازیابی', $user, ['remaining' => $remaining], null);
        }

        return $remaining;
    }

    /**
     * ده کدِ تازه به جای همه‌ی قبلی‌ها (قبلی‌ها همان لحظه باطل).
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->freshCodes();
        $user->forceFill(['two_factor_recovery_codes' => $this->digests($codes)])->save();

        Audit::log('two_factor.recovery_codes_regenerated', 'ساختِ دوباره‌ی کدهای بازیابیِ سوپر ادمین', $user, [], null);

        return $codes;
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count($this->storedDigests($user));
    }

    /** پاک‌کردنِ کاملِ عاملِ دوم — فقط از فرمانِ سرور (`admin:two-factor --reset`) */
    public function reset(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_pending_secret' => null,
            'two_factor_pending_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();

        Audit::log('two_factor.reset', 'بازنشانیِ ورودِ دومرحله‌ایِ سوپر ادمین از فرمانِ سرور', $user, [], null);
    }

    /** شکلِ یکسانِ کدِ بازیابی پیش از HMAC: بی فاصله و خط تیره، حروفِ کوچک */
    public function normalizeRecoveryCode(string $code): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $code) ?? '');
    }

    private function accountLabel(User $user): string
    {
        return (string) ($user->username ?? $user->phone ?? $user->id);
    }

    /** @return list<string> */
    private function freshCodes(): array
    {
        // بی نویسه‌های هم‌شکل (0/o، 1/l/i)؛ ده نویسه از ۳۲ ≈ ۵۰ بیت
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $codes = [];
        while (count($codes) < self::RECOVERY_CODES) {
            $raw = '';
            for ($i = 0; $i < 10; $i++) {
                $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $code = substr($raw, 0, 5).'-'.substr($raw, 5);
            if (! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /** @param  list<string>  $codes */
    private function digests(array $codes): string
    {
        return (string) json_encode(array_map(fn (string $code) => $this->digest($code), $codes));
    }

    private function digest(string $code): string
    {
        return hash_hmac('sha256', $this->normalizeRecoveryCode($code), (string) config('app.key'));
    }

    /** @return list<string> */
    private function storedDigests(User $user): array
    {
        $decoded = json_decode((string) $user->two_factor_recovery_codes, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
