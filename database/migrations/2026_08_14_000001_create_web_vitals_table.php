<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دادهٔ میدانیِ Core Web Vitals (R38).
 *
 * ─── چرا وقتی Lighthouse هست ───────────────────────────────────────────────
 * Lighthouse یک بار، روی یک دستگاه، با شبکه‌ی شبیه‌سازی‌شده اندازه می‌گیرد.
 * چیزی که در رتبه‌ی جستجو اثر دارد **دادهٔ میدانی** است: همان عددی که روی
 * گوشیِ ساکنی با اینترنتِ همراه ثبت می‌شود. این دو معمولاً خیلی فرق دارند و
 * فقط دومی قابلِ اقدام است.
 *
 * ─── چرا ردیفِ خام و نه میانگینِ از پیش حساب‌شده ──────────────────────────
 * ⚠️ میانگینِ Core Web Vitals **گمراه‌کننده** است. معیارِ گوگل صدکِ ۷۵ است،
 * نه میانگین: سایتی که ۷۰٪ کاربرانش تجربه‌ی عالی و ۳۰٪ تجربه‌ی افتضاح
 * دارند، میانگینِ قابلِ‌قبول می‌گیرد ولی در Search Console مردود است.
 * صدک را فقط از روی ردیف‌های خام می‌شود حساب کرد.
 *
 * حجم با `PruneWebVitals` مهار می‌شود، نه با ذخیره‌ی خلاصه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_vitals', function (Blueprint $table) {
            $table->id();

            /** LCP / CLS / INP / TTFB / FCP */
            $table->string('metric', 8)->index();

            /**
             * مقدار بر حسبِ میلی‌ثانیه — جز CLS که بی‌واحد است.
             *
             * `decimal` و نه `integer`: CLS عددی مثل ‎۰٫۰۸۳ است و با
             * گِردکردن به صحیح، همه‌ی مقادیرِ خوب صفر می‌شدند.
             */
            $table->decimal('value', 10, 4);

            /** `good` / `needs-improvement` / `poor` — طبقِ آستانه‌ی گوگل. */
            $table->string('rating', 20)->index();

            /** مسیرِ صفحه، بدونِ کوئری: `/`، `/demo`، `/dashboard` */
            $table->string('path', 191)->index();

            /**
             * نوعِ دستگاه.
             *
             * تفکیکش لازم است چون آستانه‌ها یکی است ولی واقعیتِ سخت‌افزار
             * نه؛ اگر همه را با هم ببینیم، افتِ موبایل زیرِ عددِ خوبِ
             * دسکتاپ پنهان می‌شود.
             */
            $table->string('device', 10)->index();

            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_vitals');
    }
};
