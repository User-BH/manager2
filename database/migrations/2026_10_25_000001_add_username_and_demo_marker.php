<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نام کاربری و نشانگرِ دادهٔ آزمایشی (مرحلهٔ ۱۳ · فاز ۱؛ پیامِ کارفرما ۲۰۲۶-۱۰-۱۹، بخش‌های ۲ و ۳).
 *
 * `users.username`: شناسه‌ی ورودِ سوپر ادمین و حساب‌های آزمایشیِ Preview — کنارِ شماره، نه جای آن. خالی برای همه‌ی
 * حساب‌های موجود؛ کاربرِ واقعی همچنان با شماره وارد می‌شود. یکتا، چون شناسه‌ی ورود است.
 *
 * `users.is_demo` و `complexes.is_demo`: نشانگرِ canonical ِ سمتِ سرور («داشبورد های آزمایشی»، بندِ ۴.۳). فقط
 * seeder ِ Preview آن را می‌نویسد (هیچ مسیرِ API و هیچ `fillable`ی نه)؛ تشخیص از نام، شماره یا متن نیست. پیش‌فرض
 * `false`، پس هیچ ردیفِ موجودی آزمایشی نمی‌شود.
 *
 * `down()` فقط همین سه ستون را برمی‌دارد؛ چیزی جز نام‌های کاربری و نشانگر از دست نمی‌رود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 32)->nullable()->unique();
            $table->boolean('is_demo')->default(false)->index();
        });

        Schema::table('complexes', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('complexes', function (Blueprint $table) {
            $table->dropIndex(['is_demo']);
            $table->dropColumn('is_demo');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_demo']);
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'is_demo']);
        });
    }
};
