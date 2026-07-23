<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دستگاه‌های مورداعتمادِ «مرا به خاطر بسپار».
 *
 * چرا جدول جدا و نه مکانیزم داخلی remember لاراول؟ چون سه شرط هم‌زمان لازم
 * است: مدتِ دقیقِ ۱۰ روز، ردکردنِ احراز دومرحله‌ای روی دستگاهِ شناخته‌شده، و
 * باطل‌شدن با خروج از حساب. کوکیِ recaller لاراول «تا ابد» است و کنترل این
 * سه با هم را نمی‌دهد، پس دستگاه‌های مورداعتماد را خودمان نگه می‌داریم.
 *
 * توکن به‌صورت hash ذخیره می‌شود؛ نسخه‌ی خام فقط داخل کوکیِ امضاشده‌ی مرورگر
 * است، درست مثل رمز عبور که هش می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('label')->nullable();   // خلاصه‌ی user-agent برای نمایش به کاربر
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');
    }
};
