<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * یادآورهای تقویم (مرحلهٔ ۱۳ · فاز ۷؛ ایرادات ۱۰، بندهای ۷۷ تا ۹۳).
 *
 * هر ردیف یک **سری** است: یک‌باره (بی `repeat_every_days`) یا تکرارشونده (هر N روز، تا تاریخ، چند بار، یا بی‌پایان —
 * تصمیمِ ۲.۳-۸). نمونه‌ها ساخته و ذخیره نمی‌شوند — سریِ بی‌پایان نمی‌تواند — بلکه از قاعده بازسازی می‌شوند؛ «فقط همین
 * نمونه» با ردیفِ `reminder_skips` و یادآورِ یک‌باره‌ی تازه، «همه‌ی بعدی‌ها» با بستنِ سری و سریِ تازه.
 *
 * 🔴 «تاریخِ رویداد» (`target_date`) و «زمانِ یادآوری» دو مفهومِ جدایند (بندِ ۷۸): یادآوری = رویداد منهای `lead_days`، در
 * ساعتِ `remind_time` ِ تهران. `next_remind_at` همان لحظه به UTC برای نمونه‌ی بعدیِ اعلان‌نشده است — زمان‌بند فقط همین
 * ستونِ ایندکس‌دار را می‌خواند.
 *
 * `complex_id` زمینه است (بندهای ۸۷ و ۹۹): یادآورِ مجتمعِ الف در مجتمعِ ب دیده نمی‌شود؛ سوپر ادمین در زمینه‌ی سکو (null).
 * یادآور دادهٔ شخصیِ صاحبش است: با حذفِ سختِ حساب یا مجتمع می‌رود (هیچ‌کدام سابقه‌ی مالی یا مشترک نیست).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('complex_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title', 150);
            $table->string('description', 1000)->nullable();
            // تاریخِ رویدادِ نمونه‌ی اول (میلادی؛ رابط شمسی نشان می‌دهد)
            $table->date('target_date');
            // یادآوری چند روز پیش از رویداد (۰ = همان روز)
            $table->unsignedSmallInteger('lead_days')->default(0);
            // ساعتِ یادآوری به وقتِ تهران، «HH:MM»
            $table->string('remind_time', 5)->default('09:00');
            $table->unsignedSmallInteger('repeat_every_days')->nullable();
            $table->date('repeat_until')->nullable();
            $table->unsignedSmallInteger('repeat_count')->nullable();
            // نمونه‌ی بعدیِ اعلان‌نشده؛ هر دو null یعنی سری تمام شده
            $table->date('next_occurrence')->nullable();
            $table->dateTime('next_remind_at')->nullable()->index();
            $table->dateTime('last_notified_at')->nullable();
            $table->unsignedInteger('notified_count')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'complex_id']);
        });

        Schema::create('reminder_skips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reminder_id')->constrained()->cascadeOnDelete();
            $table->date('occurrence');
            $table->timestamps();

            $table->unique(['reminder_id', 'occurrence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_skips');
        Schema::dropIfExists('reminders');
    }
};
