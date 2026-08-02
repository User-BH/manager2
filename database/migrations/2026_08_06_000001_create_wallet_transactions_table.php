<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفترِ کیفِ پولِ واحد (R22).
 *
 * ─── چرا ستونِ «مانده» نداریم ───────────────────────────────────────────────
 * وسوسه‌ی همیشگی این است که یک ستونِ `balance` روی `units` بگذاریم و با هر
 * تراکنش کم و زیادش کنیم. آن ستون دیر یا زود با واقعیت فرق می‌کند: یک
 * به‌روزرسانیِ ازدست‌رفته، یک تراکنشِ برگشت‌خورده، یا یک اسکریپتِ اصلاحی که
 * ردیف را دست‌کاری می‌کند و دفتر را نه — و از آن لحظه هیچ‌کس نمی‌داند کدام
 * درست است.
 *
 * پس **مانده هیچ‌جا ذخیره نمی‌شود**: همیشه از جمعِ همین ردیف‌ها حساب می‌شود.
 * دفتر تنها منبعِ حقیقت است و ردیف‌هایش هرگز ویرایش یا حذف نمی‌شوند؛ اصلاح
 * با ردیفِ معکوس انجام می‌شود، همان‌طور که در حسابداریِ واقعی.
 *
 * `balance_after` فقط **عکسِ لحظه‌ای** برای صورت‌حساب است، نه مرجع. تستی هست
 * که این دو را با هم مقایسه می‌کند تا اگر روزی واگرا شدند، بی‌صدا نماند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('complex_id')->constrained()->cascadeOnDelete();

            /*
             * کیفِ پول به **واحد** تعلق دارد نه به کاربر: قبض هم مالِ واحد
             * است، و مالک و مستاجر در طول زمان عوض می‌شوند بی‌آنکه بدهیِ
             * واحد عوض شود.
             */
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();

            // credit = پول وارد کیف شد · debit = از کیف خرج شد
            $table->string('direction', 10);

            $table->decimal('amount', 16, 2);

            // عکسِ لحظه‌ای برای صورت‌حساب؛ مرجع نیست
            $table->decimal('balance_after', 16, 2);

            /*
             * منبع: از کجا آمد یا کجا رفت. برای صورت‌حسابِ خوانا و برای
             * ردگیریِ اینکه یک شارژ از کدام مسیر انجام شده.
             */
            $table->string('source', 30);

            /*
             * حذفِ پرداخت یا قبض نباید ردِ دفتر را پاک کند — همان درسِ R14 و
             * R15 درباره‌ی نابودیِ تاریخچه‌ی مالی.
             */
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bill_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();

            $table->timestamps();

            // صورت‌حسابِ کیف پول: همه‌ی ردیف‌های یک واحد به ترتیب زمان
            $table->index(['unit_id', 'id']);
            $table->index(['complex_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
