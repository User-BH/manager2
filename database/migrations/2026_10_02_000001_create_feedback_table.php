<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بازخوردِ بازدیدکنندگان از صفحه‌ی پشتیبانی (مرحلهٔ ۷ · فاز ۴) — فقط افزودنی.
 *
 * سطحِ پلتفرم است، نه مجتمع: فرستنده ممکن است هنوز حسابی نداشته باشد، و
 * خواننده‌اش فقط ادمینِ کل است.
 *
 * ⚠️ IPِ خام نگه داشته نمی‌شود. `sender_hash` (HMACِ IP با کلیدِ برنامه)
 * فقط برای تشخیصِ ارسالِ تکراری است؛ از رویش نمی‌شود به IP رسید.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->string('category', 20);
            $table->text('message');
            $table->string('name', 80)->nullable();
            $table->string('phone', 11)->nullable();
            // اگر واردشده بود؛ با حذفِ کاربر بازخورد می‌ماند و بی‌نام می‌شود
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 12)->default('new');
            $table->char('message_hash', 64);
            $table->char('sender_hash', 64);
            $table->timestamps();

            $table->index(['status', 'created_at']);
            // تشخیصِ «همین متن از همین فرستنده» بی‌اسکنِ جدول
            $table->index(['sender_hash', 'message_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
