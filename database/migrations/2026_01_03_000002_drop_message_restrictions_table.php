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
 *
 * @destructive جدولِ `message_restrictions` حذف می‌شود. هیچ کنترلر، سرویس یا
 * صفحه‌ای هرگز به آن ننوشته بود، پس ردیفی برای از دست رفتن وجود ندارد؛
 * سنجیده شد و جدول در محصول خالی بود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('message_restrictions');
    }

    /**
     * ⚠️ بازسازی باید **دقیقاً** همان چیزی باشد که مهاجرتِ سازنده ساخته بود.
     *
     * ─── نقصی که R57 پیدا کرد ───────────────────────────────────────────
     * این `down()` جدول را با ستونِ `until` و **بدونِ** کلیدِ یکتای
     * `(complex_id, user_id)` می‌ساخت، در حالی که
     * `2026_01_01_000010_create_messages_table` آن را با ستونِ `created_by`
     * و **با** آن کلید ساخته بود.
     *
     * یعنی برگشتِ این مهاجرت جدولی می‌ساخت که با هیچ نسخه‌ای از تاریخچه‌ی
     * پروژه نمی‌خواند. `down()` که چیزِ دیگری بسازد، از `down()`ی که هیچ
     * کاری نکند بدتر است: دیتابیس ظاهراً سالم است ولی نیست.
     */
    public function down(): void
    {
        Schema::create('message_restrictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complex_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(['complex_id', 'user_id']);
        });
    }
};
