<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ثبتِ خطاها در دیتابیسِ خودمان.
 *
 * ─── چرا وقتی Sentry داریم؟ ────────────────────────────────────────────────
 * چون Sentry ممکن است هرگز وصل نشود (شناسه‌اش دستِ صاحبِ پروژه است)، و پنلِ
 * ادمین نباید تا آن روز خالی بماند. ضمناً این داده مالِ خودِ ماست: نه سهمیه
 * دارد، نه به سرویسِ بیرونی وابسته است، و برای دیدنش نیاز به حسابِ دیگری
 * نیست. Sentry وقتی وصل شد، مکمل است نه جایگزین.
 *
 * ─── ستونِ `fingerprint` ───────────────────────────────────────────────────
 * هزار بارِ یک خطای تکراری در پنل، ارزشِ تحلیلی ندارد و فقط قابلِ‌مرور نیست.
 * با اثرِ انگشتِ یکسان، رخدادهای هم‌خانواده گروه می‌شوند و `occurrences` بالا
 * می‌رود به‌جای اینکه ردیفِ تازه ساخته شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_events', function (Blueprint $table) {
            $table->id();

            /** `server` یا `client` — خطای PHP یا خطای رندرِ مرورگر. */
            $table->string('source', 20)->index();

            /** هشِ نوع+پیام+محل، برای گروه‌کردنِ رخدادهای یکسان. */
            $table->string('fingerprint', 64)->unique();

            $table->string('type', 191);
            $table->text('message');
            $table->string('file', 500)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->text('stack')->nullable();

            /** نشانیِ صفحه‌ای که خطا در آن رخ داد. */
            $table->string('url', 500)->nullable();
            $table->string('method', 10)->nullable();
            $table->unsignedSmallInteger('status')->nullable();

            /** برای پیگیریِ اینکه خطا مختصِ یک کاربر/مجتمع است یا همگانی. */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('complex_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();

            /** ادمین می‌تواند خطای بررسی‌شده را کنار بگذارد بی‌آنکه پاک شود. */
            $table->boolean('is_resolved')->default(false)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_events');
    }
};
