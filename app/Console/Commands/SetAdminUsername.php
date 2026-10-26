<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit;
use App\Support\Phone;
use App\Support\Username;
use Illuminate\Console\Command;

/**
 * نام کاربریِ سوپر ادمینِ اصلی («حفره ها۲»، بندِ ۵.۱؛ تصمیمِ د-۱).
 *
 * ورودِ سوپر ادمین «نام کاربری + رمز + Authenticator» است و `admin:two-factor` هم با نام کاربری کار می‌کند — ولی تا این
 * فرمان هیچ راهِ پشتیبانی‌شده‌ای برای دادنِ نام کاربری به سوپر ادمینِ موجود نبود: `username` عمداً `fillable` نیست و هیچ
 * مسیرِ API آن را نمی‌نویسد. این‌جا فقط از شل ِ سرور، فقط برای سوپر ادمینِ واقعی، با audit log.
 */
class SetAdminUsername extends Command
{
    protected $signature = 'admin:username
        {username : نام کاربری: حرفِ کوچکِ لاتین در آغاز، سپس حرف، رقم، نقطه یا زیرخط (۳ تا ۳۲)}
        {--phone= : شماره‌ی موبایلِ سوپر ادمین}';

    protected $description = 'نام کاربریِ ورودِ سوپر ادمینِ واقعی را می‌گذارد یا عوض می‌کند';

    /** چرا این نام کاربری پذیرفتنی نیست؛ `null` یعنی پذیرفتنی */
    public static function problem(string $username, ?int $exceptId = null): ?string
    {
        if (! Username::isValid($username)) {
            return 'نام کاربری باید با حرفِ کوچکِ لاتین شروع شود و فقط حرف، رقم، نقطه یا زیرخط داشته باشد (۳ تا ۳۲ نویسه).';
        }

        // پیشوندِ حساب‌های آزمایشیِ Preview مالِ آن‌هاست؛ سوپر ادمینِ واقعی با آن‌ها اشتباه گرفته نشود
        if (str_starts_with($username, 'demo.')) {
            return 'پیشوندِ «demo.» مالِ حساب‌های آزمایشیِ Preview است.';
        }

        $taken = User::withoutGlobalScopes()->where('username', $username)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))->exists();

        return $taken ? "نام کاربریِ «{$username}» از پیش گرفته شده است." : null;
    }

    public function handle(): int
    {
        $phone = Phone::normalize($this->option('phone') ?: $this->ask('شماره موبایل سوپر ادمین'));
        $user = User::withoutGlobalScopes()->where('phone', $phone)->first();

        if (! $user || ! $user->isSuperAdmin() || $user->is_demo) {
            $this->error("سوپر ادمینِ واقعی‌ای با شمارهٔ {$phone} پیدا نشد.");

            return self::FAILURE;
        }

        $username = Username::normalize((string) $this->argument('username'));
        if (($error = self::problem($username, $user->id)) !== null) {
            $this->error($error);

            return self::FAILURE;
        }

        $previous = $user->username;
        $user->forceFill(['username' => $username])->save();

        Audit::log('admin.username_set', 'تعیینِ نام کاربریِ سوپر ادمین', $user, [
            'from' => $previous,
            'to' => $username,
        ]);

        $this->info("نام کاربریِ «{$user->name}» شد «{$username}».");
        $this->line('ورود: /auth/username با همین نام کاربری و رمز؛ سپس کدِ Authenticator (docs/ADMIN_TWO_FACTOR.md).');

        return self::SUCCESS;
    }
}
