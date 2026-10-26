<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Preview;
use Database\Seeders\PreviewSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ساختنِ دوباره‌ی پایگاه‌داده‌ی Preview از صفر، با دادهٔ نمونه (مرحلهٔ ۹ · فاز ۸).
 *
 * تنها راهِ ساختن و برچسب‌زدنِ پایگاه‌داده‌ی Preview. سه قفل پیش از هر نوشتن:
 *
 *   ① فقط با `APP_ENV=preview` — روی محصول، توسعه یا آزمون اجرا نمی‌شود؛
 *   ② نه روی پایگاه‌داده‌ای که نامش `PREVIEW_PRODUCTION_DATABASE` است؛
 *   ③ نه روی پایگاه‌داده‌ی **بی‌برچسبی که کاربر دارد** — شاید محصول است، و
 *      `migrate:fresh` یعنی پاک‌شدنِ همه‌چیز. پایگاه‌داده‌ی خالی یا برچسب‌دار فقط.
 *
 * دادهٔ نمونه `PreviewSeeder` است (مرحلهٔ ۱۳ · فاز ۱؛ ساختارِ «حفره ها۲»، ۲۰۲۶-۱۰-۲۶): چهار حسابِ آزمایشی با نام
 * کاربری — ثبت‌نامِ اولیه، تک‌مجتمعی، چندمجتمعی با پنج زمینه، سوپر ادمینِ آزمایشی؛ هیچ دادهٔ محصولی وارد Preview نمی‌شود —
 * این دستور راهی به پایگاه‌داده‌ی دیگری ندارد. رمزِ حساب‌ها تصادفی است و فقط همین‌جا یک بار چاپ می‌شود؛ در مخزن نیست.
 * افزودنِ چیزِ جاافتاده بی پاک‌کردن: `preview:seed`؛ بازگرداندنِ یک حساب: `preview:reset-account`.
 */
class PreviewReset extends Command
{
    protected $signature = 'preview:reset {--force : بی پرسیدن (برای اسکریپتِ استقرار)}';

    protected $description = 'پایگاه‌داده‌ی Preview را پاک و با دادهٔ نمونه از نو می‌سازد (فقط APP_ENV=preview)';

    public function handle(): int
    {
        if (! Preview::active()) {
            $this->error('این دستور فقط روی Preview (APP_ENV=preview) اجرا می‌شود. محیطِ فعلی: '.app()->environment());

            return self::FAILURE;
        }

        $database = DB::connection()->getDatabaseName();
        $production = config('preview.production_database');

        if (filled($production) && $database === $production) {
            $this->error("پایگاه‌داده‌ی «{$database}» همان پایگاه‌داده‌ی محصول است؛ پاک نمی‌شود.");

            return self::FAILURE;
        }

        if (Preview::databaseRole() !== Preview::ENVIRONMENT && $this->hasUsers()) {
            $this->error("پایگاه‌داده‌ی «{$database}» برچسبِ Preview ندارد و کاربر دارد — شاید محصول است؛ پاک نمی‌شود. "
                .'برای Preview یک پایگاه‌داده‌ی خالیِ جدا بسازید و در .env ِ Preview بگذارید.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("همه‌ی داده‌ی «{$database}» پاک و دادهٔ نمونه نوشته شود؟")) {
            $this->line('لغو شد.');

            return self::SUCCESS;
        }

        $this->call('migrate:fresh', ['--force' => true]);
        $seeder = app(PreviewSeeder::class);
        $seeder->run();
        Preview::markDatabase();

        if ($this->call('blog:import', ['--no-interaction' => true]) !== self::SUCCESS) {
            $this->warn('واردکردنِ مقاله‌های بلاگ ناموفق بود؛ Preview بی‌مقاله هم سالم است.');
        }

        $this->info("پایگاه‌داده‌ی Preview («{$database}») ساخته و برچسب خورد.");
        PreviewSeedCommand::printIssued($this, $seeder->issued());

        return self::SUCCESS;
    }

    private function hasUsers(): bool
    {
        return Schema::hasTable('users') && User::withoutGlobalScopes()->exists();
    }
}
