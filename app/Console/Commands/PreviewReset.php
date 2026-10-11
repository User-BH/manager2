<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Preview;
use Database\Seeders\DemoSeeder;
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
 * دادهٔ نمونه همان `DemoSeeder` است (چهار حسابِ آزمایشی)؛ هیچ دادهٔ محصولی وارد
 * Preview نمی‌شود — این دستور اصلاً راهی به پایگاه‌داده‌ی دیگری ندارد.
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
        $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
        Preview::markDatabase();

        if ($this->call('blog:import', ['--no-interaction' => true]) !== self::SUCCESS) {
            $this->warn('واردکردنِ مقاله‌های بلاگ ناموفق بود؛ Preview بی‌مقاله هم سالم است.');
        }

        $this->info("پایگاه‌داده‌ی Preview («{$database}») ساخته و برچسب خورد.");
        $this->table(['نقش', 'شماره', 'رمز'], [
            ['ادمینِ کل', '09120000001', 'password'],
            ['ثبت‌نامِ اولیه (بی‌مجتمع)', DemoSeeder::FRESH_SIGNUP_PHONE, 'password'],
            ['مدیرِ مجتمع', '09120000000', 'password'],
            ['واحد', '09121111101', 'password'],
        ]);

        return self::SUCCESS;
    }

    private function hasUsers(): bool
    {
        return Schema::hasTable('users') && User::withoutGlobalScopes()->exists();
    }
}
