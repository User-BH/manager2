<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complex_id')->constrained()->cascadeOnDelete();
            $table->foreignId('building_id')->nullable()->constrained()->nullOnDelete();

            $table->string('unit_number');          // شماره واحد
            $table->smallInteger('floor')->default(0); // طبقه (می‌تواند منفی باشد: پارکینگ/زیرزمین)
            $table->decimal('area', 8, 2)->default(0); // متراژ (متر مربع)
            $table->unsignedSmallInteger('residents_count')->default(1); // تعداد نفرات ساکن
            $table->unsignedSmallInteger('parking_count')->default(0);

            $table->string('occupancy_status', 20)->default('vacant'); // owner_occupied | rented | vacant

            // ضریب اختصاصی برای تقسیم هزینه‌ها (پیش‌فرض ۱)
            $table->decimal('coefficient', 8, 4)->default(1);
            // آیا واحد از آسانسور استفاده می‌کند (برای تقسیم هزینه آسانسور)
            $table->boolean('uses_elevator')->default(true);

            // مانده‌ی بدهی کش‌شده (مثبت = بدهکار). منبع حقیقت، جمع bills است.
            $table->decimal('balance', 16, 2)->default(0);

            $table->string('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['complex_id', 'unit_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
