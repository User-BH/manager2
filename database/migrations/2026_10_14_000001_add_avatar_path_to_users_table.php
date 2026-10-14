<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تصویرِ پروفایل — canonical، روی خودِ حساب (مرحلهٔ ۱۰ · فاز ۷؛ بندِ ۵ ِ فایلِ معماری).
 *
 * یک تصویر برای حساب، نه برای هر مجتمع یا هر سرویس: پیام‌رسان، منوی کاربر و فهرست‌ها همه همین را می‌خوانند و
 * عوض شدنش همه‌جا دیده می‌شود. فایل روی دیسکِ خصوصی است و فقط از مسیرِ کنترل‌شده سرو می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
