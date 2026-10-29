<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\PersianName;
use App\Support\Phone;
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
        {--password= : رمز عبور؛ اگر ندهید پنهان پرسیده می‌شود (ترجیحاً ندهید تا در تاریخچه‌ی شل نماند)}
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

        $this->newLine();
        $this->info('کاربر ادمین کل ساخته شد.');
        $this->line("  نام:   {$user->name}");
        $this->line("  شماره: {$user->phone}");
        $this->line('  نقش:   '.$user->role->label());
        $this->newLine();
        // ورودِ سوپر ادمین روی Production بی Authenticator بسته است (۲۰۲۶-۱۰-۲۹، بندِ ۲) — گامِ بعدی همین است
        $this->line('گامِ بعد (پیش از نخستین ورود): php artisan admin:two-factor '.$user->phone.' — docs/ADMIN_TWO_FACTOR.md');
        $this->line('ورود: صفحه‌ی /auth با همین شماره و رمز، سپس کدِ شش‌رقمیِ برنامه‌ی Authenticator (بی پیامک).');

        return self::SUCCESS;
    }
}
