<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بازیابیِ حساب وقتی شماره‌ی قبلی در دسترس نیست (مرحلهٔ ۹ · تصمیمِ کارفرما).
 *
 * درخواست با اثباتِ مالکیتِ **شماره‌ی تازه** (کدِ پیامکی) ثبت می‌شود و شماره‌ی
 * canonical فقط با تأییدِ ادمینِ کل پس از بررسیِ شواهد عوض می‌شود. `user_id` حسابِ
 * منطبق با شماره‌ی قبلی است و ممکن است تهی باشد (پاسخ به درخواست‌کننده نمی‌گوید).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_recovery_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('old_phone', 20);
            $table->string('new_phone', 20);
            $table->string('national_id', 20)->nullable();
            $table->text('explanation');
            $table->string('status', 20)->default('pending');
            $table->timestamp('new_phone_verified_at');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_recovery_requests');
    }
};
