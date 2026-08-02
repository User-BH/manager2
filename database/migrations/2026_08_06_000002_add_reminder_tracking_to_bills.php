<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * شمارنده‌ی یادآوریِ قبض (R22).
 *
 * ─── نکته‌ای که حین کار پیدا شد ────────────────────────────────────────────
 * ستونِ `last_reminded_at` **از قبل وجود داشت** — بازمانده‌ی یادآوریِ پیامکی
 * که بعداً حذف شد (طبق قید: پیامک فقط برای کدِ یک‌بارمصرف). ستون ماند ولی
 * هیچ‌کس پرش نمی‌کرد.
 *
 * پس دوباره ساخته نمی‌شود؛ همان ستون برای یادآوریِ **درون‌برنامه‌ای** به کار
 * می‌رود و فقط شمارنده اضافه می‌شود.
 *
 * ─── چرا شمارنده لازم است ──────────────────────────────────────────────────
 * بدونِ سقف، قبضی که هرگز پرداخت نشود تا ابد اعلان می‌سازد. کسی که چهار بار
 * یادآوری گرفته با پنجمی پرداخت نمی‌کند؛ فقط یاد می‌گیرد اعلان‌ها را
 * نادیده بگیرد — و آن‌وقت اعلانِ مهمِ بعدی را هم نمی‌بیند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->unsignedTinyInteger('reminders_sent')->default(0)->after('last_reminded_at');

            /*
             * دستورِ یادآوری با این ایندکس قبض‌های سررسیدشده‌ی پرداخت‌نشده را
             * پیدا می‌کند. بدونِ آن، هر اجرا کلِ جدول را می‌خواند.
             */
            $table->index(['status', 'due_date'], 'bills_reminder_index');
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropIndex('bills_reminder_index');
            $table->dropColumn('reminders_sent');
        });
    }
};
