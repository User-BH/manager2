<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «اطلاعاتِ مجتمع» (مرحلهٔ ۱۰ · فاز ۷؛ بندهای ۷ و ۱۸ ِ فایلِ معماری).
 *
 * ─── فقط آنچه از داده بیرون نمی‌آید ─────────────────────────────────────────
 * بندِ ۲: «هر داده یک منبعِ حقیقت». پس تعدادِ ساختمان، بلوک، طبقه، واحد، فضای تجاری، مالک، مستأجر، واحدِ خالی و
 * فعال و فهرستِ مدیران **ذخیره نمی‌شوند** — هر بار از ساختمان‌ها، واحدها، دوره‌های سکونت و عضویت‌ها حساب می‌شوند
 * (`ComplexInformationController`). نام، نوع، نشانی و تلفن هم ستون‌های موجودِ همین جدول‌اند. این‌جا فقط چیزهایی
 * است که مدیر باید بنویسد: هویتِ تکمیلی، ساختار و بخش‌های مشترک، امکانات، و اطلاعاتِ مدیریت.
 *
 * `information_completed_at` نخستین تکمیل را نگه می‌دارد؛ تا پر نشده، داشبوردِ مدیر یادآوری نشان می‌دهد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complexes', function (Blueprint $table) {
            // ۷.۱ هویتی (نام، نوع و نشانی از پیش هستند)
            $table->string('brand_name', 150)->nullable();
            $table->string('province', 60)->nullable();
            $table->string('city', 60)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('description')->nullable();
            // ۷.۲ ساختاری — فقط آنچه شمردنی نیست
            $table->string('structure', 500)->nullable();
            $table->string('common_areas', 500)->nullable();
            // ۷.۴ امکانات: کلیدهای فهرستِ ثابت + «سایر»
            $table->json('facilities')->nullable();
            $table->string('facilities_other', 255)->nullable();
            // ۷.۵ مدیریت (تلفنِ تماس همان `phone` است و مدیران از عضویت‌ها)
            $table->string('board', 500)->nullable();
            $table->string('response_hours', 150)->nullable();
            $table->text('emergency_info')->nullable();
            $table->timestamp('information_completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('complexes', function (Blueprint $table) {
            $table->dropColumn([
                'brand_name', 'province', 'city', 'postal_code', 'latitude', 'longitude', 'description',
                'structure', 'common_areas', 'facilities', 'facilities_other',
                'board', 'response_hours', 'emergency_info', 'information_completed_at',
            ]);
        });
    }
};
