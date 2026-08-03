<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * پیوست، رسیدِ خواندن و نظرسنجیِ درون‌چت (R23b).
 *
 * هر سه به مخاطب‌دهیِ R23a وابسته‌اند: «چه کسی خواند» بدونِ گیرنده معنا
 * ندارد، و نظرسنجی هم باید بداند از چه کسانی می‌پرسد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            /*
             * پیوستِ فایل. مسیر روی دیسکِ خصوصی می‌ماند و فقط از یک مسیرِ
             * کنترل‌شده سرو می‌شود — همان الگوی رسیدِ پرداخت (R19)، چون
             * پیوستِ یک گفت‌وگوی خصوصی هم نباید مستقیم از public خوانده شود.
             */
            $table->string('attachment_path')->nullable()->after('body');
            $table->string('attachment_name')->nullable()->after('attachment_path');

            // نوعِ نمایشی: `image` درون‌خطی نشان داده می‌شود، بقیه دانلود
            $table->string('attachment_kind', 10)->nullable()->after('attachment_name');
        });

        /*
         * رسیدِ خواندن.
         *
         * ردیف فقط وقتی ساخته می‌شود که کسی پیام را ببیند؛ «خوانده‌نشده» با
         * **نبودِ ردیف** نشان داده می‌شود نه با پرچمِ false. این‌طور جدول به
         * اندازه‌ی خواندن‌های واقعی رشد می‌کند، نه به اندازه‌ی
         * پیام×کاربر.
         */
        Schema::create('message_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');

            $table->unique(['message_id', 'user_id']);
            $table->index('user_id');
        });

        /*
         * نظرسنجیِ درون‌چت.
         *
         * نظرسنجی خودش یک پیام است (نه موجودیتِ جدا) تا در همان جریانِ
         * گفت‌وگو بنشیند و مخاطب‌دهی، مخفی‌کردن و رسیدِ خواندنش رایگان به
         * دست بیاید.
         */
        Schema::create('message_polls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('question', 255);

            // بستنِ نظرسنجی: پس از آن رأیِ تازه پذیرفته نمی‌شود
            $table->timestamp('closes_at')->nullable();

            $table->timestamps();
            $table->unique('message_id');
        });

        Schema::create('poll_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_poll_id')->constrained()->cascadeOnDelete();
            $table->string('label', 120);
            $table->unsignedTinyInteger('sort_order')->default(0);
        });

        Schema::create('poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_poll_id')->constrained()->cascadeOnDelete();
            $table->foreignId('poll_option_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            /*
             * یک رأی برای هر کاربر در هر نظرسنجی — **نه** برای هر گزینه.
             * قیدِ یکتا روی (نظرسنجی، کاربر) است، پس تعویضِ رأی یعنی
             * به‌روزرسانیِ همان ردیف و کسی نمی‌تواند دو گزینه را هم‌زمان
             * انتخاب کند.
             */
            $table->unique(['message_poll_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poll_votes');
        Schema::dropIfExists('poll_options');
        Schema::dropIfExists('message_polls');
        Schema::dropIfExists('message_reads');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['attachment_path', 'attachment_name', 'attachment_kind']);
        });
    }
};
