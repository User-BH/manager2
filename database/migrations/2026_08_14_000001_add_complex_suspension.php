<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تعلیقِ مجتمع توسط ادمینِ کل (R29).
 *
 * ─── باگی که پیدا شد ───────────────────────────────────────────────────────
 * ستونِ `complexes.is_active` از اولین مهاجرت وجود داشت و در `fillable` و
 * `casts` هم بود — ولی **هیچ‌جای برنامه خوانده نمی‌شد**. یعنی ادمینِ کل
 * می‌توانست مجتمعی را «غیرفعال» ثبت کند و ساکنانش دقیقاً مثل قبل کار
 * می‌کردند: قبض می‌دیدند، پیام می‌دادند، پرداخت می‌کردند.
 *
 * دقیقاً همان خانواده‌ی باگِ `unit_user.end_date` در R26: ستونی که هست،
 * پر می‌شود، و هیچ‌کس بر اساسش تصمیم نمی‌گیرد. و باز هم به همان دلیل
 * زنده مانده بود — هیچ صفحه‌ای اثرش را نشان نمی‌داد.
 *
 * این مهاجرت **دلیل و زمانِ** تعلیق را اضافه می‌کند؛ اعمالِ خودِ قاعده در
 * `EnsureComplexActive` است.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complexes', function (Blueprint $table) {
            /*
             * زمانِ تعلیق جدا از `is_active` نگه داشته می‌شود.
             *
             * فقط یک پرچمِ بولی نمی‌گوید «از کِی» و «چرا»؛ و وقتی مدیرِ
             * مجتمع تماس می‌گیرد، پشتیبانی باید بتواند جواب بدهد. بدونِ
             * این دو ستون، تنها منبع، حافظه‌ی کسی بود که دکمه را زده.
             */
            $table->timestamp('suspended_at')->nullable()->after('is_active');
            $table->string('suspension_reason', 255)->nullable()->after('suspended_at');
        });
    }

    public function down(): void
    {
        Schema::table('complexes', function (Blueprint $table) {
            $table->dropColumn(['suspended_at', 'suspension_reason']);
        });
    }
};
