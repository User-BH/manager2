<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit;
use App\Support\Phone;
use App\Support\Username;
use Illuminate\Console\Command;

/**
 * نام کاربریِ ورودِ هر مدیرِ سامانه (۲۰۲۶-۱۰-۳۰، بندِ ۴ ِ کارفرما): دو مدیر از `/auth/admin` با نام کاربری + رمز + TOTP وارد
 * می‌شوند. `username` در هیچ API نیست و `fillable` نیست — فقط از شل ِ سرور، فقط برای سوپر ادمینِ واقعی، با audit log.
 *
 * نام کاربری بی‌حساسیت به بزرگی و کوچکیِ حروف است: «vahidDeep» ذخیره و پذیرفته می‌شود چون همیشه کوچک‌شده سنجیده می‌شود.
 */
class SetAdminUsername extends Command
{
    protected $signature = 'admin:username
        {phone : شماره‌ی موبایلِ سوپر ادمین}
        {username : نام کاربری: حرفِ لاتین در آغاز، سپس حرف، رقم، نقطه یا زیرخط (۳ تا ۳۲)}';

    protected $description = 'نام کاربریِ ورودِ یک مدیرِ سامانه (/auth/admin) را می‌گذارد یا عوض می‌کند';

    /** چرا این نام کاربری پذیرفتنی نیست؛ `null` یعنی پذیرفتنی */
    public static function problem(string $username, ?int $exceptId = null): ?string
    {
        if (! Username::isValid($username)) {
            return 'نام کاربری باید با حرفِ لاتین شروع شود و فقط حرف، رقم، نقطه یا زیرخط داشته باشد (۳ تا ۳۲ نویسه).';
        }

        // پیشوندِ حساب‌های نمونه‌ی Preview مالِ آن‌هاست
        if (str_starts_with(Username::normalize($username), 'demo.')) {
            return 'پیشوندِ «demo.» مالِ حساب‌های آزمایشیِ Preview است.';
        }

        $taken = User::withoutGlobalScopes()->where('username', Username::normalize($username))
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))->exists();

        return $taken ? 'نام کاربریِ «'.$username.'» از پیش گرفته شده است.' : null;
    }

    public function handle(): int
    {
        $phone = Phone::normalize((string) $this->argument('phone'));
        $user = User::withoutGlobalScopes()->where('phone', $phone)->first();

        if (! $user || ! $user->isSuperAdmin() || $user->is_demo) {
            $this->error("سوپر ادمینِ واقعی‌ای با شمارهٔ {$phone} پیدا نشد.");

            return self::FAILURE;
        }

        $username = (string) $this->argument('username');
        if (($error = self::problem($username, $user->id)) !== null) {
            $this->error($error);

            return self::FAILURE;
        }

        $previous = $user->username;
        $user->forceFill(['username' => Username::normalize($username)])->save();

        Audit::log('admin.username_set', 'تعیینِ نام کاربریِ مدیرِ سامانه', $user, ['from' => $previous, 'to' => $user->username], null);

        $this->info("نام کاربریِ «{$user->name}» شد «{$username}» (بی‌حساسیت به حروفِ بزرگ و کوچک).");
        $this->line('ورود: /auth/admin با همین نام کاربری و رمز، سپس کدِ Authenticator.');

        return self::SUCCESS;
    }
}
