<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فیلدهای تکمیلی صفحه‌ی «پروفایل من».
 *
 * همه اختیاری‌اند: کاربری که مدیر ساخته فقط نام و شماره دارد و نباید
 * به‌خاطر این ستون‌ها ناقص شمرده شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('birth_date')->nullable()->after('national_id');
            $table->string('emergency_phone', 20)->nullable()->after('birth_date');
            $table->string('address')->nullable()->after('emergency_phone');
            $table->string('bio', 500)->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['birth_date', 'emergency_phone', 'address', 'bio']);
        });
    }
};
