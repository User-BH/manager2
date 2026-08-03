<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تنظیماتِ اعلانِ کاربر و سهمیه‌ی ماهانه‌ی پیامک (R27).
 *
 * ─── درباره‌ی پیامک ────────────────────────────────────────────────────────
 * قاعده‌ی محصول تا امروز این بود: **پیامک فقط برای کدِ یک‌بارمصرف**. کارفرما
 * یک استثنای دقیق تعریف کرد و نه بیشتر:
 *
 *   مدیرِ هر مجتمع **ماهی یک بار** می‌تواند یک یادآوریِ پرداختِ شارژ به
 *   ساکنینِ بدهکار بفرستد، و **فقط وقتی** هزینه‌های آن دوره را در سامانه
 *   وارد کرده باشد.
 *
 * دو قید در همین جمله‌اند و هر دو در دیتابیس اعمال می‌شوند: یکتاییِ
 * (مجتمع، دوره) سهمیه را تضمین می‌کند، و شرطِ صدورِ قبض جلوی پیامکِ زودهنگام
 * را می‌گیرد. سهمیه در دیتابیس شمرده می‌شود و نه در کش — کشِ پاک‌شده نباید
 * سهمیه‌ی تازه بدهد.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * تنظیماتِ اعلانِ هر کاربر.
         *
         * ─── چرا جدولِ جدا و نه ستون روی `users` ─────────────────────────────
         * تعدادِ کانال‌ها و انواعِ اعلان با هر مرحله زیاد می‌شود؛ با ستون، هر
         * بار یک مهاجرتِ تازه روی جدولِ پرترافیکِ `users` لازم بود. ردیف فقط
         * وقتی ساخته می‌شود که کاربر چیزی را **خاموش** کند، پس پیش‌فرضِ
         * «همه روشن» بدونِ هیچ ردیفی کار می‌کند.
         */
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // کلیدِ نوعِ اعلان (`bill.due`، `service_request`، …)
            $table->string('channel_key', 40);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'channel_key']);
        });

        /*
         * کارزارِ پیامکِ ماهانه.
         *
         * یکتاییِ (مجتمع، دوره) **خودِ سهمیه** است: ردیفِ دوم برای یک دوره
         * اصلاً ساخته نمی‌شود، حتی اگر دو مدیر هم‌زمان کلیک کنند. این را با
         * شمارشِ برنامه‌ای نمی‌شد تضمین کرد.
         */
        Schema::create('sms_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complex_id')->constrained()->cascadeOnDelete();

            // دوره‌ی جلالی `YYYY-MM` — همان قالبِ `bills.period`
            $table->string('period', 7);

            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedSmallInteger('recipients')->default(0);
            $table->unsignedSmallInteger('delivered')->default(0);
            $table->unsignedSmallInteger('failed')->default(0);

            /*
             * متنِ فرستاده‌شده عیناً نگه داشته می‌شود.
             *
             * قالبِ پیام ممکن است بعداً عوض شود؛ بدونِ این ستون، مدیری که
             * ماه‌ها بعد می‌پرسد «دقیقاً چه چیزی برای ساکنین رفت؟» جوابی
             * نداشت — و در دعوای ساختمانی این جواب مهم است.
             */
            $table->text('template');

            $table->timestamps();

            $table->unique(['complex_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_campaigns');
        Schema::dropIfExists('notification_settings');
    }
};
