<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاریخچه‌ی ورود (مرحلهٔ ۵ · فاز ۱۰).
 *
 * هر ورودِ موفق و هر تلاشِ ناموفق روی حسابی که **وجود دارد** یک ردیف است.
 * تلاش روی شماره‌ی ناموجود ثبت نمی‌شود: صاحبی ندارد که ببیندش، و ثبتش فقط
 * شماره‌ی آدم‌های بیرونی را انبار می‌کرد.
 *
 * `device_hash` هشِ رشته‌ی مرورگر است، برای «این دستگاه را قبلاً دیده‌ام؟»؛
 * خودِ رشته هم برای نمایش به صاحبِ حساب می‌ماند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event', 30);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->char('device_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'device_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_events');
    }
};
