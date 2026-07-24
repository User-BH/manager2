<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * زمان پذیرش قوانین و مقررات.
 *
 * تیکِ «قوانین را مطالعه کرده‌ام» تا امروز فقط سمت کاربر بررسی می‌شد و هیچ
 * ردی نمی‌گذاشت؛ یعنی اگر روزی اختلافی پیش می‌آمد، هیچ سندی نبود که کاربر
 * شرایط را پذیرفته. حالا لحظه‌ی پذیرش ثبت می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('terms_accepted_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('terms_accepted_at');
        });
    }
};
