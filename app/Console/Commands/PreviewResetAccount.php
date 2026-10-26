<?php

namespace App\Console\Commands;

use App\Support\Preview;
use Database\Seeders\PreviewSeeder;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * بازگرداندنِ **یک** حسابِ آزمایشیِ Preview به سناریوی خودش («حفره ها۲»، بندهای ۴.۱ و ۴.۶).
 *
 * برخلافِ `preview:reset` هیچ جدولی را خالی نمی‌کند و به حساب‌های دیگر دست نمی‌زند: عضویت‌ها و درخواست‌های همان حساب را
 * از مسیرهای واقعیِ دامنه به جدولِ سند برمی‌گرداند (کارِ `PreviewSeeder::restore`). رمز فقط با `--password` عوض می‌شود
 * و رمزِ تازه یک بار، فقط در خروجیِ همین فرمان، می‌آید.
 *
 * فقط روی Preview ِ برچسب‌دار: روی محصول، توسعه یا پایگاه‌داده‌ی بی‌برچسب اجرا نمی‌شود.
 */
class PreviewResetAccount extends Command
{
    protected $signature = 'preview:reset-account
        {username : یکی از demo.initial، demo.single، demo.multi، demo.admin}
        {--password : رمزِ تصادفیِ تازه هم صادر شود (رمزِ فعلی باطل می‌شود)}';

    protected $description = 'یک حسابِ آزمایشیِ Preview را به سناریوی خودش برمی‌گرداند؛ رمز فقط با --password (فقط APP_ENV=preview)';

    public function handle(): int
    {
        if (! Preview::active()) {
            $this->error('این دستور فقط روی Preview (APP_ENV=preview) اجرا می‌شود. محیطِ فعلی: '.app()->environment());

            return self::FAILURE;
        }

        if (Preview::databaseRole() !== Preview::ENVIRONMENT) {
            $this->error('پایگاه‌داده برچسبِ Preview ندارد؛ نخست `preview:reset` را روی پایگاه‌داده‌ی خالیِ Preview اجرا کنید.');

            return self::FAILURE;
        }

        $username = (string) $this->argument('username');

        try {
            $result = app(PreviewSeeder::class)->restore($username, (bool) $this->option('password'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result['actions'] === []) {
            $this->info("«{$username}» از پیش در سناریوی خودش بود؛ چیزی عوض نشد.");
        } else {
            $this->info("«{$username}» به سناریوی خودش برگشت:");
            foreach ($result['actions'] as $action) {
                $this->line(" - {$action}");
            }
        }

        if ($result['password'] !== null) {
            PreviewSeedCommand::printIssued($this, [['label' => $username, 'username' => $username, 'password' => $result['password']]]);
        }

        return self::SUCCESS;
    }
}
