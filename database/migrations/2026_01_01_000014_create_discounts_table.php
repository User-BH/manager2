<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// تخفیف یا بخشودگی اختصاصی هر واحد در یک دوره. هنگام صدور قبض اعمال می‌شود.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complex_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7); // 1404-03
            $table->decimal('amount', 16, 2);
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['unit_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
