<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بنرهای تبلیغاتی صفحه‌ی فرود.
 *
 * تا پیش از این، تبلیغات یک آرایه‌ی ثابت داخل کد بود و هر تغییری نیازمند
 * ویرایش فایل و استقرار دوباره می‌شد. حالا از پنل مدیریت خوانده می‌شوند.
 *
 * تبلیغات به مجتمع خاصی تعلق ندارند (صفحه‌ی فرود عمومی است)، پس `complex_id`
 * ندارند و مدیریتشان کار ادمین کل است.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advertisements', function (Blueprint $table) {
            $table->id();

            $table->string('title', 150);
            $table->string('subtitle', 255)->nullable();
            $table->string('href', 500);

            /*
             * دو منبع تصویر پشتیبانی می‌شود:
             *  - image_path: فایل آپلودشده روی دیسک خصوصی که از مسیر
             *    کنترل‌شده سرو می‌شود.
             *  - image_url: مسیر ثابت داخل public (برای بنرهای پیش‌فرضی که
             *    همراه پروژه می‌آیند).
             */
            $table->string('image_path')->nullable();
            $table->string('image_url')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            // بازه‌ی نمایش کمپین؛ خالی یعنی بدون محدودیت زمانی
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advertisements');
    }
};
