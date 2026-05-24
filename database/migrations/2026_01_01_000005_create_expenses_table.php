<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complex_id')->constrained()->cascadeOnDelete();

            $table->string('title'); // مثلا «قبض آب مشترک خرداد»
            $table->decimal('amount', 16, 2);
            $table->string('category', 10); // owner | tenant
            $table->string('period', 7);    // دوره‌ی شمسی مثل 1404-03
            $table->date('spend_date')->nullable();
            $table->string('vendor')->nullable();

            // اگر این هزینه باید بین واحدها تقسیم شود، روش تقسیم (نوع استخری) و پارامترها.
            // null یعنی صرفا از صندوق خرج شده و در قبض واحدها نمی‌آید.
            $table->string('split_method', 30)->nullable();
            $table->json('split_config')->nullable();
            $table->json('target_units')->nullable();
            $table->boolean('is_distributed')->default(false);

            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['complex_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
