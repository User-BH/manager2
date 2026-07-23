<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حذف جدول بلااستفاده‌ی `message_restrictions`.
 *
 * این جدول از ابتدا ساخته شد ولی هیچ کنترلر، سرویس یا صفحه‌ای به آن دست
 * نمی‌زد. کاری که قرار بود انجام دهد (محدودکردن یک ساکن در پیام‌رسان) از
 * قبل با ستون `users.can_message` انجام می‌شد و MessengerController هم
 * همان را بررسی می‌کرد؛ فقط رابط کاربری‌اش وجود نداشت که حالا اضافه شده.
 *
 * نگه‌داشتن دو مکانیزم موازی برای یک قابلیت، بعداً باعث واگرایی می‌شد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('message_restrictions');
    }

    public function down(): void
    {
        Schema::create('message_restrictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complex_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('until')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }
};
