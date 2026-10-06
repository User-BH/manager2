<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نام و نام خانوادگیِ جدا روی حساب (مرحلهٔ ۹ · فاز ۳ · تصمیمِ ۱۲ ِ کارفرما).
 *
 * `name` می‌ماند و از این پس **مشتق** است: هرجا هر دو بخش پر باشند، مدلِ
 * `User` آن را می‌سازد؛ پس یک منبعِ حقیقت. ستون‌ها nullable‌اند چون نامِ حساب‌های
 * قبلی عمداً با حدس شکسته نمی‌شود («وحید حسنعلی پور مرادی» کدام بخش است؟) —
 * آن حساب‌ها نامشان را نگه می‌دارند و درخواستِ تکمیل می‌بینند.
 *
 * فقط افزودنی است؛ هیچ داده‌ای عوض یا حذف نمی‌شود و `down` برگشت‌پذیر است.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 60)->nullable()->after('name');
            $table->string('last_name', 60)->nullable()->after('first_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name']);
        });
    }
};
