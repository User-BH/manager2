<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اشاره‌ی اختیاری به پکیجِ دیتابیسی.
 *
 * ستونِ رشته‌ایِ `plan` (enumِ قدیمی) دست‌نخورده می‌ماند تا اشتراک‌های قبلی
 * کار کنند؛ اشتراک‌های تازه (خرید یا فعال‌سازیِ دستی) `plan_id` می‌گیرند و
 * PlanGate اگر `plan_id` باشد، قابلیت‌ها را از پکیجِ دیتابیسی می‌خواند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->after('plan')
                ->constrained('plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
        });
    }
};
