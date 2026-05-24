<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// قبض/صورت‌حساب ماهانه‌ی هر واحد در هر دوره.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complex_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();

            $table->string('period', 7); // 1404-03

            // تفکیک سهم مالکانه و مستاجرانه
            $table->decimal('owner_amount', 16, 2)->default(0);
            $table->decimal('tenant_amount', 16, 2)->default(0);

            $table->decimal('base_amount', 16, 2)->default(0);     // جمع اجزا پیش از جریمه/تخفیف
            $table->decimal('penalty_amount', 16, 2)->default(0);
            $table->decimal('discount_amount', 16, 2)->default(0);
            $table->decimal('total_amount', 16, 2)->default(0);    // مبلغ نهایی قابل پرداخت
            $table->decimal('paid_amount', 16, 2)->default(0);

            $table->string('status', 12)->default('unpaid'); // BillStatus

            $table->date('due_date')->nullable();
            $table->date('paid_at')->nullable();
            $table->timestamp('issued_at')->nullable();

            // ریز اجزای محاسبه برای شفافیت کامل برای ساکن
            $table->json('breakdown')->nullable();

            $table->timestamps();

            $table->unique(['unit_id', 'period']);
            $table->index(['complex_id', 'period']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
