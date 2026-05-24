<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// درآمدهای غیرشارژ (اجاره‌ی پشت‌بام، پارکینگ مهمان، سود بانکی و ...).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complex_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->decimal('amount', 16, 2);
            $table->string('source')->nullable();
            $table->string('period', 7); // 1404-03
            $table->date('received_date')->nullable();
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['complex_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incomes');
    }
};
