<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Phone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * تغییر رمز عبور یک کاربر از خط فرمان.
 *
 * برای وقتی که ادمین رمزش را فراموش کرده و راهی برای ورود به پنل ندارد.
 */
class ResetUserPassword extends Command
{
    protected $signature = 'admin:password
        {--phone= : شماره موبایل کاربر}
        {--password= : رمز جدید؛ اگر ندهید پرسیده می‌شود}';

    protected $description = 'تغییر رمز عبور یک کاربر (مثلاً ادمین فراموش‌کرده)';

    public function handle(): int
    {
        $phone = Phone::normalize($this->option('phone') ?: $this->ask('شماره موبایل کاربر'));

        // بدون global scope، تا ادمین کل (که مجتمع ندارد) هم پیدا شود.
        $user = User::withoutGlobalScopes()->where('phone', $phone)->first();

        if (! $user) {
            $this->error("کاربری با شمارهٔ {$phone} پیدا نشد.");

            $known = User::withoutGlobalScopes()->count();
            if ($known === 0) {
                $this->line('در دیتابیس هیچ کاربری وجود ندارد. برای ساخت ادمین:  php artisan admin:create');
            }

            return self::FAILURE;
        }

        $password = $this->option('password') ?: $this->secret('رمز جدید');

        $check = Validator::make(['password' => $password], [
            'password' => ['required', Password::min(8)],
        ]);

        if ($check->fails()) {
            $this->error($check->errors()->first('password'));

            return self::FAILURE;
        }

        $user->update(['password' => Hash::make($password)]);

        $this->info("رمز عبور «{$user->name}» ({$user->phone}) تغییر کرد.");

        if (! $user->is_active) {
            $this->warn('توجه: این کاربر غیرفعال است و با همین وضعیت نمی‌تواند وارد شود.');
        }

        return self::SUCCESS;
    }
}
