<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اشتراک پرو.
 *
 * برخلاف جدول payments که پرداختِ شارژِ یک واحد است، این جدول پرداخت
 * بابت خودِ سرویس است: مدیر مجتمع اشتراک می‌خرد تا امکانات پرو برای کل
 * مجتمعش باز شود. برای همین unit_id ندارد و complex_id آن nullable است
 * (ادمین کل ممکن است هنوز مجتمعی انتخاب نکرده باشد).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complex_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // خریدار

            $table->string('plan', 20);              // SubscriptionPlan
            $table->string('status', 10)->default('pending'); // pending|active|failed|expired|canceled
            $table->decimal('amount', 16, 2);
            $table->unsignedSmallInteger('months');

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // مسیر بانکی، هم‌شکل با جدول payments
            $table->string('gateway', 20)->nullable();
            $table->string('ref_id')->nullable();
            $table->string('tracking_code')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->index(['complex_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
