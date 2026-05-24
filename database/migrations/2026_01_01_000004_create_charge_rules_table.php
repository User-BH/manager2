<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// قوانین شارژ پایه و تکرارشونده‌ی هر مجتمع.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charge_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complex_id')->constrained()->cascadeOnDelete();

            $table->string('name'); // مثلا «شارژ ثابت پایه» یا «نظافت بر اساس متراژ»
            $table->string('type', 30); // ChargeRuleType
            $table->string('category', 10)->default('tenant'); // owner | tenant

            // پارامترهای محاسبه بسته به نوع:
            // amount, base, per_area_rate, per_person_rate, exempt_ground_floor ...
            $table->json('config')->nullable();

            // برای قوانین استخری (تقسیم یک مبلغ کل)، مبلغ ثابت ماهانه‌ی قابل تقسیم
            $table->decimal('pool_amount', 16, 2)->nullable();

            // اگر فقط برخی واحدها مشمول‌اند، آرایه‌ی شناسه‌ی واحدها؛ null یعنی همه
            $table->json('target_units')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charge_rules');
    }
};
