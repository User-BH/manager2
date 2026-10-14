<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «اطلاعاتِ واحد» (مرحلهٔ ۱۰ · فاز ۷؛ بندِ ۹ ِ فایلِ معماری).
 *
 * شماره، طبقه، ساختمان، مساحت، تعدادِ پارکینگ و انباری و وضعیتِ سکونت از پیش ستون‌اند؛ مالک، مستأجر و اطلاعاتِ
 * تماسشان از دوره‌های سکونت و حساب‌ها خوانده می‌شوند — هیچ‌کدام کپی نمی‌شود (بندِ ۲). این‌جا فقط مشخصاتِ توصیفی:
 * بلوک، نوع، اتاق و خواب و سرویس، شماره‌ی پارکینگ و انباری، تراس، بالکن، جهت، کنتورها و توضیحات.
 *
 * `information_completed_at` نخستین تکمیل را نگه می‌دارد؛ تا پر نشده، داشبوردِ واحد یادآوری نشان می‌دهد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->string('block', 20)->nullable();
            $table->string('unit_type', 20)->nullable();
            $table->unsignedTinyInteger('rooms_count')->nullable();
            $table->unsignedTinyInteger('bedrooms_count')->nullable();
            $table->unsignedTinyInteger('bathrooms_count')->nullable();
            $table->string('parking_numbers', 100)->nullable();
            $table->string('storage_numbers', 100)->nullable();
            $table->boolean('has_terrace')->nullable();
            $table->boolean('has_balcony')->nullable();
            $table->string('orientation', 20)->nullable();
            // شماره‌ی کنتورِ آب، برق و گاز — هر کدام فقط اگر هست
            $table->json('meters')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('information_completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn([
                'block', 'unit_type', 'rooms_count', 'bedrooms_count', 'bathrooms_count', 'parking_numbers',
                'storage_numbers', 'has_terrace', 'has_balcony', 'orientation', 'meters', 'description',
                'information_completed_at',
            ]);
        });
    }
};
