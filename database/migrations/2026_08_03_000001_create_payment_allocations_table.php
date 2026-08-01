<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفترِ تخصیصِ پرداخت به قبض (R15).
 *
 * ─── مسئله ────────────────────────────────────────────────────────────────
 * یک پرداخت می‌تواند بینِ چند قبضِ معوق پخش شود (`PaymentService::allocate`).
 * تا پیش از این، فقط نتیجه‌ی نهایی ثبت می‌شد: `bills.paid_amount` بالا می‌رفت
 * و تمام. یعنی **هیچ‌جا نوشته نمی‌شد که کدام پرداخت، چقدر از کدام قبض را
 * پوشانده**.
 *
 * پیامدش عملی است، نه نظری:
 *   • «این ۵۰۰ هزار تومان کجا رفت؟» پاسخِ دقیقی نداشت.
 *   • اگر پرداختی باطل می‌شد، معلوم نبود از کدام قبض‌ها چقدر باید برگردد.
 *   • اگر `paid_amount` به هر دلیلی خراب می‌شد، راهی برای بازسازی‌اش نبود.
 *
 * حالا هر تخصیص یک ردیف است و `paid_amount` قابلِ بازسازی و راستی‌آزمایی
 * می‌شود: مجموعِ تخصیص‌های هر قبض باید با `paid_amount` آن برابر باشد.
 *
 * ─── چرا دفترِ دوطرفه‌ی کامل نه ───────────────────────────────────────────
 * برنامه‌ی کار «Ledger دوطرفه» می‌خواست. اینجا عمداً محدودتر پیاده شد، چون
 * `units.balance` در این سامانه **مشتق** است و نه مانده‌ی متغیر
 * (`recalculateBalance()` آن را از جمعِ قبض‌ها حساب می‌کند). پس چیزی که واقعاً
 * گم بود، ردِ تخصیص بود و نه ثبتِ دوطرفه‌ی حساب‌ها. با ساخته‌شدنِ کیف پول
 * (R22) که مانده‌ی واقعیِ متغیر دارد، این جدول پایه‌ی همان دفتر می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('complex_id')->constrained()->cascadeOnDelete();

            /*
             * حذفِ پرداخت یا قبض نباید ردِ تخصیص را پاک کند؛ به همین دلیل
             * `nullOnDelete` و نه `cascadeOnDelete` — همان درسی که در R14
             * درباره‌ی نابودیِ تاریخچه‌ی مالی گرفتیم.
             */
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bill_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('amount', 16, 2);

            $table->timestamps();

            // «این پرداخت روی این قبض» فقط یک بار؛ تکرارش یعنی حسابِ دوباره
            $table->unique(['payment_id', 'bill_id']);
            $table->index(['complex_id', 'created_at']);
            $table->index('bill_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
