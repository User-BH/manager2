<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جهتِ درخواستِ پیوستن (R21b).
 *
 * تا حالا فقط یک جهت وجود داشت: مدیر دعوت می‌کرد، کاربر می‌پذیرفت. حالا جهتِ
 * برعکس هم لازم است — واحد شماره‌ی مدیر را وارد می‌کند و درخواست می‌فرستد.
 *
 * ─── چرا جدولِ جدا ساخته نشد ───────────────────────────────────────────────
 * هر دو دقیقاً یک چیزند: «پیوندِ در انتظارِ تاییدِ طرفِ مقابل، بین یک کاربر و
 * یک مجتمع». تنها تفاوت این است که کدام طرف باید تایید کند. با دو جدول، دو
 * مسیرِ تاییدِ تقریباً یکسان می‌داشتیم و اصلاحِ باگ در یکی، دیگری را جا
 * می‌گذاشت.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complex_invitations', function (Blueprint $table) {
            /*
             * `invite`  — مدیر فرستاده، کاربر تایید می‌کند
             * `request` — کاربر فرستاده، مدیر تایید می‌کند
             *
             * پیش‌فرض `invite` است تا ردیف‌های موجود همان معنای قبلی را نگه
             * دارند.
             */
            $table->string('direction', 10)->default('invite')->after('role');

            // صفحه‌ی مدیر «درخواست‌های در انتظار این مجتمع» را با این می‌خواند
            $table->index(['complex_id', 'direction', 'status'], 'invitation_inbox_index');
        });
    }

    public function down(): void
    {
        Schema::table('complex_invitations', function (Blueprint $table) {
            $table->dropIndex('invitation_inbox_index');
            $table->dropColumn('direction');
        });
    }
};
