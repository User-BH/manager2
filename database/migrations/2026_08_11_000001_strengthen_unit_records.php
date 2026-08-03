<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * پرونده‌ی کاملِ واحد و تاریخچه‌ی مالکیت/سکونت (R26).
 *
 * ─── دو کمبود ──────────────────────────────────────────────────────────────
 *
 * ۱. **انباری.** پارکینگ ستون داشت و انباری نداشت، در حالی که در محاسبه‌ی
 *    شارژ و در دعواهای واقعیِ ساختمان دقیقاً هم‌وزنِ آن است.
 *
 * ۲. **تاریخچه در عمل نگه داشته نمی‌شد.** جدولِ `unit_user` از اول برای
 *    نگه‌داشتنِ سابقه ساخته شده بود (`is_current` + `start_date` +
 *    `end_date`)، ولی کدی که ساکن را جابه‌جا می‌کرد از
 *    `syncWithoutDetaching` استفاده می‌کرد — و آن، ردیفِ موجودِ همان
 *    (واحد، کاربر) را **بازنویسی** می‌کند. یعنی مستاجری که واحد ۵ را ترک
 *    کرده و دو سال بعد برگشته، دوره‌ی اولش پاک می‌شد. `end_date` هم هرگز
 *    پر نمی‌شد، پس حتی دوره‌های بسته‌شده تاریخِ پایان نداشتند.
 *
 * این مهاجرت ستونِ انباری را اضافه می‌کند و ایندکسی برای خواندنِ تاریخچه
 * می‌سازد؛ اصلاحِ خودِ رفتار در `TenureService` است.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            // هم‌وزنِ `parking_count`؛ در قوانین شارژ هم قابل استفاده می‌شود
            $table->unsignedSmallInteger('storage_count')->default(0)->after('parking_count');
        });

        Schema::table('unit_user', function (Blueprint $table) {
            /*
             * تاریخچه‌ی یک واحد همیشه بر حسبِ زمان خوانده می‌شود، و تاریخچه‌ی
             * یک شخص بر حسبِ خودش. بدونِ این دو ایندکس، هر بار بازکردنِ
             * پرونده‌ی یک واحد یک پیمایشِ کاملِ جدول بود.
             */
            $table->index(['unit_id', 'start_date']);
            $table->index(['user_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::table('unit_user', function (Blueprint $table) {
            $table->dropIndex(['unit_id', 'start_date']);
            $table->dropIndex(['user_id', 'is_current']);
        });

        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('storage_count');
        });
    }
};
