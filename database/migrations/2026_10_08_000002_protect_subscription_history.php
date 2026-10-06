<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تاریخچه‌ی مالیِ اشتراک با حذفِ والد پاک نمی‌شود (مرحلهٔ ۹ · فاز ۴؛ یافته‌ی Audit ِ ۹/۲).
 *
 * پیش از این هر دو کلیدِ خارجی `cascadeOnDelete` بودند: حذفِ کاربرِ خریدار یا
 * مجتمع، ردیفِ پرداختِ اشتراک را هم می‌برد.
 *
 * • `complex_id` اجباری و `restrictOnDelete`: اشتراک مالِ مجتمع است (تصمیمِ
 *   کارفرما)؛ مجتمعی که اشتراک دارد با حذفِ سخت نمی‌رود.
 * • `user_id` nullable و `nullOnDelete`: خریدار شاید برود، پرداخت می‌ماند.
 *
 * ⚠️ Production خالی است، ولی مهاجرت باز هم با داده‌ی ناسازگار بی‌صدا کنار
 * نمی‌آید: اگر ردیفی بی‌مجتمع باشد، می‌ایستد و چیزی را حذف نمی‌کند.
 *
 * @destructive فقط nullable بودنِ دو ستون عوض می‌شود (`->change()`؛ در SQLite
 * یعنی بازسازیِ جدول با همان داده). هیچ ردیفی حذف یا خالی نمی‌شود: پیش از
 * تغییر، نبودِ ردیفِ بی‌مجتمع سنجیده می‌شود، و `down` اگر خریدارِ خالی ببیند
 * می‌ایستد به‌جای اینکه داده را دور بریزد.
 *
 * ─── 🔴 قیدِ CHECK در SQLite گم می‌شد ───────────────────────────────────
 * `MigrationSafetyTest` گرفتش: SQLite برای تغییرِ ستون یا کلیدِ خارجی جدول را
 * از نو می‌سازد، و بازسازیِ لاراول قیدِ `chk_subscriptions_amount_non_negative`
 * (R52) را **بی‌صدا** می‌انداخت — در `up` و در `down`. MySQL ِ محصول آن را
 * نگه می‌دارد (قید روی ستونِ دیگری است)، ولی هر جا گم شود، اینجا برمی‌گردد.
 */
return new class extends Migration
{
    /** همان قیدِ R52 (`2026_08_26_000002_add_financial_check_constraints`) */
    private const CHECK = ['chk_subscriptions_amount_non_negative', 'amount >= 0'];

    public function up(): void
    {
        $hadCheck = $this->hasCheck();

        $orphans = DB::table('subscriptions')->whereNull('complex_id')->count();
        if ($orphans > 0) {
            throw new RuntimeException(
                "{$orphans} اشتراک بی‌مجتمع وجود دارد؛ پیش از این مهاجرت باید تعیینِ تکلیف شوند. هیچ داده‌ای حذف نشد.",
            );
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['complex_id']);
            $table->dropForeign(['user_id']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('complex_id')->nullable(false)->change();
            $table->unsignedBigInteger('user_id')->nullable()->change();

            $table->foreign('complex_id')->references('id')->on('complexes')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        $this->restoreCheck($hadCheck);
    }

    public function down(): void
    {
        $buyerless = DB::table('subscriptions')->whereNull('user_id')->count();
        if ($buyerless > 0) {
            throw new RuntimeException(
                "{$buyerless} اشتراک خریدار ندارد؛ بازگشت به user_id ِ اجباری داده را دور می‌ریخت. هیچ داده‌ای تغییر نکرد.",
            );
        }

        $hadCheck = $this->hasCheck();

        /*
         * `plan_id` هم برداشته و **پس از** آن دو دوباره گذاشته می‌شود — همان
         * معنا (`nullOnDelete`)؛ فقط تا ترتیبِ کلیدها دقیقاً مثلِ پیش از `up` شود.
         */
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['complex_id']);
            $table->dropForeign(['user_id']);
            $table->dropForeign(['plan_id']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('complex_id')->nullable()->change();
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('complex_id')->references('id')->on('complexes')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('plan_id')->references('id')->on('plans')->nullOnDelete();
        });

        $this->restoreCheck($hadCheck);
    }

    /** آیا قیدِ R52 روی جدول هست؟ (روی موتوری که اصلاً نصبش نکرده، نه) */
    private function hasCheck(): bool
    {
        return match (DB::getDriverName()) {
            'sqlite' => str_contains((string) DB::table('sqlite_master')->where('name', 'subscriptions')->value('sql'), self::CHECK[0]),
            'mysql', 'mariadb' => DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', 'subscriptions')
                ->where('CONSTRAINT_NAME', self::CHECK[0])
                ->exists(),
            default => false,
        };
    }

    /** اگر پیش از تغییر بود و بازسازی انداختش، همان را برمی‌گرداند — نه بیشتر */
    private function restoreCheck(bool $had): void
    {
        if ($had && ! $this->hasCheck()) {
            DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT '.self::CHECK[0].' CHECK ('.self::CHECK[1].')');
        }
    }
};
