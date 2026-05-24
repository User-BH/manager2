<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complexes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable()->unique();
            $table->string('address')->nullable();
            $table->string('phone', 20)->nullable();

            // واحد پول قابل تنظیم
            $table->string('currency', 10)->default('toman'); // toman | rial

            // پیام‌رسان و خوش‌حسابی
            $table->boolean('messenger_enabled')->default(true);
            $table->boolean('good_payer_enabled')->default(true);
            $table->json('good_payer_config')->nullable();

            // درگاه پرداخت مجتمع
            $table->string('payment_gateway', 20)->default('none'); // none | fake | mellat | saman
            $table->json('gateway_config')->nullable();

            // قوانین مهلت و جریمه‌ی پیش‌فرض
            $table->unsignedTinyInteger('charge_due_day')->default(10);
            $table->boolean('penalty_enabled')->default(false);
            $table->string('penalty_type', 20)->default('percent_per_day'); // fixed | percent | percent_per_day
            $table->decimal('penalty_value', 12, 2)->default(0);
            $table->unsignedSmallInteger('penalty_grace_days')->default(0);

            // موجودی صندوق (کش‌شده) و تنظیمات آزاد
            $table->decimal('fund_balance', 16, 2)->default(0);
            $table->json('settings')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complexes');
    }
};
