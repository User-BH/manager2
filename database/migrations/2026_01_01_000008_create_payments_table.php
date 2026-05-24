<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complex_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // پرداخت‌کننده

            $table->decimal('amount', 16, 2);
            $table->string('method', 10);  // PaymentMethod: online | receipt | cash
            $table->string('status', 10)->default('pending'); // PaymentStatus
            $table->string('period', 7)->nullable();

            // پرداخت آنلاین
            $table->string('gateway', 20)->nullable();
            $table->string('ref_id')->nullable();       // شناسه‌ی مرجع درگاه
            $table->string('tracking_code')->nullable(); // کد رهگیری/تراکنش
            $table->timestamp('paid_at')->nullable();

            // آپلود رسید
            $table->string('receipt_path')->nullable();
            $table->string('receipt_original_name')->nullable();
            $table->date('receipt_paid_on')->nullable();

            // بازبینی توسط مدیر
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();

            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['complex_id', 'status']);
            $table->index(['unit_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
