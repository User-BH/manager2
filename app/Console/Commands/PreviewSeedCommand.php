<?php

namespace App\Console\Commands;

use App\Support\Preview;
use Database\Seeders\PreviewSeeder;
use Illuminate\Console\Command;

/**
 * افزودنِ دادهٔ نمونه‌ی جاافتاده به Preview، بی پاک‌کردنِ چیزی (مرحلهٔ ۱۳ · فاز ۱؛ «داشبورد های آزمایشی» بندِ ۶.۳).
 *
 * برخلافِ `preview:reset` هیچ جدولی را خالی نمی‌کند. `PreviewSeeder` idempotent است: هر چه هست دست نمی‌خورد — به‌ویژه
 * رمزِ حسابی که بازبین عوضش کرده — و فقط آنچه نیست ساخته می‌شود. پس پس از هر استقرار می‌شود بی‌خطر اجرایش کرد.
 *
 * فقط روی Preview ِ برچسب‌دار: روی محصول، توسعه یا پایگاه‌داده‌ی بی‌برچسب اجرا نمی‌شود.
 */
class PreviewSeedCommand extends Command
{
    protected $signature = 'preview:seed';

    protected $description = 'دادهٔ نمونه‌ی جاافتاده‌ی Preview را می‌افزاید؛ چیزی پاک یا رمزی عوض نمی‌شود (فقط APP_ENV=preview)';

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

        $seeder = app(PreviewSeeder::class);
        $seeder->run();

        // ساختارِ پیشینِ ۱۳/۱: غیرفعال شد، نه حذف؛ پاک‌سازیِ کامل فقط با `preview:reset`
        foreach ($seeder->retired() as $username) {
            $this->warn("حسابِ «{$username}» از ساختارِ پیشینِ حساب‌های آزمایشی است و غیرفعال شد؛ برای پاک‌سازیِ کامل `preview:reset` را اجرا کنید.");
        }

        self::printIssued($this, $seeder->issued());

        return self::SUCCESS;
    }

    /**
     * رمزِ حساب‌هایی که همین اجرا ساخت — یک بار، فقط در خروجیِ همین فرمان. حسابِ موجود این‌جا نمی‌آید چون رمزش
     * عوض نشده و معلوم هم نیست.
     *
     * @param  list<array{label: string, username: string, password: string}>  $issued
     */
    public static function printIssued(Command $command, array $issued): void
    {
        if ($issued === []) {
            $command->info('همه‌ی حساب‌های آزمایشی از پیش بودند؛ رمزی عوض نشد.');

            return;
        }

        $command->warn('رمزهای زیر فقط همین یک بار نشان داده می‌شوند؛ جایی امن نگهشان دارید.');
        $command->table(['حساب', 'نام کاربری', 'رمز'], array_map(
            fn (array $row): array => [$row['label'], $row['username'], $row['password']],
            $issued,
        ));
    }
}
