<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// رابطه‌ی چند-به-چند ساکنین و واحدها. هر سطر یک رابطه‌ی مالکیت یا سکونت است.
// با نگه‌داشتن سطرهای پایان‌یافته (is_current=false) سابقه‌ی جابه‌جایی حفظ می‌شود.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complex_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('relation', 10); // owner | tenant
            // سهم مالکیت برای واحدهای چندمالکی (جمع باید ۱۰۰ شود)
            $table->decimal('share_percent', 5, 2)->default(100);

            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_current')->default(true);

            $table->timestamps();

            $table->index(['unit_id', 'relation', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_user');
    }
};
