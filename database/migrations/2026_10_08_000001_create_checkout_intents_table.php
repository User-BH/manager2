<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قصدِ خرید پیش از وجودِ مجتمع (مرحلهٔ ۹ · فاز ۴؛ اصلاحِ قطعیِ کارفرما).
 *
 * کاربرِ «اولیه» هنوز مجتمعی ندارد، ولی اشتراک **مالِ مجتمع** است و نباید
 * برای حلِ این مسئله به کاربر وصل شود. پس همه‌ی داده‌ی پیش از پرداخت — پلن،
 * مبلغِ سرور، نام و نوعِ مجتمعِ آینده، مرجعِ درگاه — اینجا می‌نشیند؛ مجتمع،
 * اشتراک و نقشِ مدیر فقط پس از پرداختِ تأییدشده و در یک تراکنش ساخته می‌شوند
 * و این ردیف به آن‌ها اشاره می‌کند.
 *
 * ─── قیدها، نه فقط کد ───────────────────────────────────────────────────
 * • `public_id` تصادفی و یکتا: نشانیِ بازگشت از بانک شمارنده‌ی ردیف‌ها را لو نمی‌دهد.
 * • `(gateway, authority)` یکتا: یک مرجعِ درگاه، یک قصد.
 * • `tracking_code` یکتا: یک کدِ رهگیری، یک پرداخت.
 * • `subscription_id` یکتا: یک قصد هرگز دو اشتراک نمی‌سازد.
 * • کلیدهای خارجی `nullOnDelete` / `restrictOnDelete`: حذفِ کاربر یا مجتمع
 *   سابقه‌ی مالی را پاک نمی‌کند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_intents', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // پلن و تصویرِ تاریخیِ آن لحظه — قیمتِ بعدیِ پلن این ردیف را عوض نمی‌کند
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('plan_name');
            $table->unsignedBigInteger('amount'); // تومان، از سرور
            $table->unsignedSmallInteger('months');

            // مجتمعِ آینده
            $table->string('complex_name', 150);
            $table->string('complex_type', 20);
            $table->string('complex_address')->nullable();

            $table->string('status', 12)->default('pending')->index();
            $table->string('gateway', 20)->nullable();
            $table->string('authority', 100)->nullable();
            $table->string('tracking_code', 100)->nullable()->unique();
            $table->string('failure_reason')->nullable();

            $table->foreignId('complex_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->unique()->constrained()->restrictOnDelete();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'authority']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_intents');
    }
};
