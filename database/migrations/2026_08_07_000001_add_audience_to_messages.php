<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مخاطبِ پیام (R23).
 *
 * ─── وضعیتِ پیش از این ─────────────────────────────────────────────────────
 * پیام‌رسان یک کانالِ واحد بود: هر پیام به **همه‌ی** اهالیِ مجتمع می‌رسید و
 * هیچ مفهومِ «گیرنده» وجود نداشت. یعنی ساکن نمی‌توانست چیزی خصوصی به مدیر
 * بگوید، و مدیر نمی‌توانست فقط به یک واحد پیام بدهد.
 *
 * ─── مدلِ تازه ─────────────────────────────────────────────────────────────
 *   management → ساکن به مدیریت (خصوصیِ همان واحد)
 *   all        → مدیر به همه
 *   units      → مدیر به یک یا چند واحدِ انتخابی
 *
 * ─── چرا گفت‌وگو در سطحِ «واحد» است و نه «کاربر» ───────────────────────────
 * بقیه‌ی سامانه (قبض، کیفِ پول، بدهی) همه به واحد تعلق دارند و مالک و
 * مستاجر در طول زمان عوض می‌شوند. اگر گفت‌وگو به کاربر بسته می‌شد، مالک و
 * مستاجرِ یک واحد دو رشته‌ی جدا می‌داشتند و مدیر باید حدس می‌زد کدام را
 * جواب بدهد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            /*
             * پیش‌فرض `all` است تا پیام‌های موجود دقیقاً همان رفتارِ قبلی را
             * نگه دارند: تا امروز هر پیام برای همه بوده.
             */
            $table->string('audience', 20)->default('all')->after('body');

            /*
             * برای پیامِ `management`: این پیام از رشته‌ی کدام واحد است.
             * حذفِ واحد نباید تاریخچه‌ی گفت‌وگو را پاک کند.
             */
            $table->foreignId('unit_id')->nullable()->after('audience')
                ->constrained()->nullOnDelete();

            // خواندنِ رشته‌ی یک واحد، و فهرستِ کلی برای مدیر
            $table->index(['complex_id', 'audience', 'unit_id'], 'messages_audience_index');
        });

        /*
         * گیرنده‌های پیامِ `units`.
         *
         * یک پیام و چند گیرنده — نه چند نسخه‌ی جدا. مدیر که به سه واحد پیام
         * می‌دهد **یک** پیام فرستاده؛ اگر سه ردیف می‌ساختیم، ویرایش یا
         * مخفی‌کردنش باید سه جا انجام می‌شد و دیر یا زود یکی جا می‌ماند.
         */
        Schema::create('message_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();

            $table->unique(['message_id', 'unit_id']);
            $table->index('unit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_units');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_audience_index');
            $table->dropConstrainedForeignId('unit_id');
            $table->dropColumn('audience');
        });
    }
};
