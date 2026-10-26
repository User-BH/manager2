<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\PersianName;
use App\Support\Phone;
use App\Support\Username;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * ساخت کاربر ادمین کل سیستم.
 *
 * `migrate --seed` هم ادمین می‌سازد، اما همراهش دادهٔ نمونهٔ کامل (مجتمع
 * ساختگی، واحدها، قبوض) هم وارد می‌شود که روی سرویس واقعی مطلوب نیست.
 * این دستور فقط همان یک کاربر را می‌سازد.
 */
class CreateAdmin extends Command
{
    protected $signature = 'admin:create
        {--phone= : شماره موبایل (۰۹xxxxxxxxx)}
        {--first-name= : نام (فارسی)}
        {--last-name= : نام خانوادگی (فارسی)}
        {--password= : رمز عبور؛ اگر ندهید پرسیده می‌شود}
        {--username= : نام کاربری برای ورود با نام کاربری + رمز + Authenticator (اختیاری؛ بعداً: admin:username)}
        {--email= : ایمیل (اختیاری)}';

    protected $description = 'ساخت کاربر ادمین کل سیستم برای ورود به پنل';

    public function handle(): int
    {
        $phone = Phone::normalize($this->option('phone') ?: $this->ask('شماره موبایل ادمین'));

        if (! Phone::isValidMobile($phone)) {
            $this->error("شمارهٔ «{$phone}» معتبر نیست؛ باید به شکل 09xxxxxxxxx باشد.");

            return self::FAILURE;
        }

        if (User::where('phone', $phone)->exists()) {
            $this->error("کاربری با شمارهٔ {$phone} از قبل وجود دارد.");
            $this->line('برای تغییر رمز همان کاربر از دستور admin:password استفاده کنید.');

            return self::FAILURE;
        }

        /*
         * نام و نام خانوادگیِ جدا (مرحلهٔ ۹ · فاز ۶) — همان قاعده‌ی ثبت‌نام
         * (`PersianName`). پیش از این یک `name` ِ تک با پیش‌فرضِ «مدیر سیستم»
         * بود و حسابِ ادمینِ کل تنها حسابی می‌ماند که نامش دو بخش نداشت.
         */
        $first = PersianName::normalize($this->option('first-name') ?: $this->ask('نام'));
        $last = PersianName::normalize($this->option('last-name') ?: $this->ask('نام خانوادگی'));

        $names = Validator::make(
            ['first_name' => $first, 'last_name' => $last],
            ['first_name' => PersianName::rules(), 'last_name' => PersianName::rules()],
            [],
            ['first_name' => 'نام', 'last_name' => 'نام خانوادگی'],
        );

        if ($names->fails()) {
            $this->error($names->errors()->first());

            return self::FAILURE;
        }

        // رمز از ورودی مخفی خوانده می‌شود تا در تاریخچهٔ شل باقی نماند.
        $password = $this->option('password') ?: $this->secret('رمز عبور');

        $check = Validator::make(['password' => $password], [
            'password' => ['required', Password::min(8)],
        ]);

        if ($check->fails()) {
            $this->error($check->errors()->first('password'));

            return self::FAILURE;
        }

        $username = $this->option('username');
        if ($username !== null && ($error = SetAdminUsername::problem(Username::normalize($username))) !== null) {
            $this->error($error);

            return self::FAILURE;
        }

        $user = User::create([
            // `name` را مدل از این دو می‌سازد
            'first_name' => $first,
            'last_name' => $last,
            'name' => PersianName::full($first, $last),
            'email' => $this->option('email'),
            'phone' => $phone,
            'password' => Hash::make($password),
            'role' => UserRole::SuperAdmin,
            'is_active' => true,
        ]);
        // نام کاربری `fillable` نیست: فقط از فرمانِ سرور، نه از درخواست
        if ($username !== null) {
            $user->forceFill(['username' => Username::normalize($username)])->save();
        }

        $this->newLine();
        $this->info('کاربر ادمین کل ساخته شد.');
        $this->line("  نام:   {$user->name}");
        $this->line("  شماره: {$user->phone}");
        if ($user->username !== null) {
            $this->line("  نام کاربری: {$user->username}");
        }
        $this->line('  نقش:   '.$user->role->label());
        $this->newLine();
        $this->line('حالا می‌توانید با همین شماره و رمز وارد پنل شوید.');
        $this->line('عاملِ دوم (Authenticator): پس از ورود از «اطلاعاتِ کاربری»، یا php artisan admin:two-factor <نام کاربری> — docs/ADMIN_TWO_FACTOR.md');

        return self::SUCCESS;
    }
}
