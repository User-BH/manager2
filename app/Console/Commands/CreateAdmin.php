<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;

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
        {--name= : نام و نام خانوادگی}
        {--password= : رمز عبور؛ اگر ندهید پرسیده می‌شود}
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

        $name = $this->option('name') ?: $this->ask('نام و نام خانوادگی', 'مدیر سیستم');

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
            'name' => $name,
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
        $this->line('حالا می‌توانید با همین شماره و رمز وارد پنل شوید.');

        return self::SUCCESS;
    }
}
