<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\AdminTwoFactor;
use App\Services\Auth\Totp;
use App\Support\Phone;
use Illuminate\Console\Command;

/**
 * راه‌اندازی یا بازنشانیِ عاملِ دومِ سوپر ادمین از روی سرور (دستورِ یکپارچه‌ی ۲۰۲۶-۱۰-۲۵، بخشِ ۵).
 *
 * راهِ ترجیحی راه‌اندازی از «اطلاعاتِ کاربری» است. این فرمان برای دو حالت است:
 *
 *   - **راه‌اندازیِ اول وقتی پیامک در دسترس نیست** (`otp_disabled` یا سوپر ادمینِ بی شماره): کلید و ده کدِ بازیابی
 *     **فقط در خروجیِ همین فرمان** چاپ می‌شوند — نه در لاگ، نه در پایگاه‌داده جز رمزنگاری‌شده. خروجی را مستقیم در
 *     vault ِ مشترک بگذارید و ترمینال را پاک کنید.
 *   - **بازیابی وقتی گوشی و همه‌ی کدهای بازیابی از دست رفته‌اند** (`--reset`): دسترسیِ شل به سرور همان احرازِ هویتِ
 *     قوی است؛ مسیرِ وبی برای غیرفعال‌سازی وجود ندارد.
 *
 * هر دو در audit log. فقط برای حسابِ سوپر ادمین.
 */
class AdminTwoFactorCommand extends Command
{
    protected $signature = 'admin:two-factor
        {phone : شماره موبایلِ سوپر ادمین}
        {--reset : پاک‌کردنِ کاملِ عاملِ دوم (گوشی و کدهای بازیابی از دست رفته‌اند)}
        {--force : بی پرسیدن}';

    protected $description = 'راه‌اندازی یا بازنشانیِ ورودِ دومرحله‌ای (Authenticator) ِ سوپر ادمین از روی سرور';

    public function handle(AdminTwoFactor $twoFactor): int
    {
        $phone = Phone::normalize((string) $this->argument('phone'));
        $user = User::withoutGlobalScopes()->where('phone', $phone)->first();

        if (! $user || ! $user->isSuperAdmin() || $user->is_demo) {
            $this->error("سوپر ادمینی با شمارهٔ «{$phone}» پیدا نشد.");

            return self::FAILURE;
        }

        if ($this->option('reset')) {
            if (! $this->option('force') && ! $this->confirm("عاملِ دومِ «{$user->name}» ({$phone}) کاملاً پاک شود؟ تا راه‌اندازیِ دوباره ورودِ این حساب روی Production بسته است.")) {
                $this->line('لغو شد.');

                return self::SUCCESS;
            }

            $twoFactor->reset($user);
            $this->info('عاملِ دوم پاک شد. اکنون دوباره راه‌اندازی کنید: php artisan admin:two-factor '.$phone);

            return self::SUCCESS;
        }

        if ($twoFactor->enabled($user)) {
            $this->error('ورودِ دومرحله‌ای از پیش فعال است. برای تعویض از «اطلاعاتِ کاربری» یا نخست --reset.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('کلید و کدهای بازیابی فقط همین یک بار در این ترمینال چاپ می‌شوند. ادامه؟')) {
            $this->line('لغو شد.');

            return self::SUCCESS;
        }

        $setup = $twoFactor->begin($user);
        // تأیید با کدِ همین لحظه از همان کلید — همان مسیرِ رابطِ وب، نه دورزدنِ آن
        $codes = $twoFactor->confirm($user->fresh() ?? $user, Totp::at($setup['secret'], Totp::step()));

        if ($codes === null) {
            $this->error('راه‌اندازی ناموفق بود؛ دوباره اجرا کنید.');

            return self::FAILURE;
        }

        $this->warn('این‌ها فقط همین یک بار نشان داده می‌شوند. مستقیم در vault ِ امن بگذارید و ترمینال را پاک کنید.');
        $this->line('کلیدِ Authenticator: '.$setup['secret']);
        $this->line('نشانیِ otpauth: '.$setup['uri']);
        $this->line('کدهای بازیابیِ یک‌بارمصرف:');
        foreach ($codes as $code) {
            $this->line('  '.$code);
        }
        // کدِ همین ۳۰ ثانیه برای تأیید مصرف شد (ضدِ replay)
        $this->line('نخستین ورود با کدِ بعدیِ برنامه (حداکثر ۳۰ ثانیه‌ی دیگر).');

        return self::SUCCESS;
    }
}
