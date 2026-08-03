<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * درخواست‌های ساکنین و واگذاری به مسئول (R25).
 *
 * ─── چرا جدولِ جدا و نه استفاده از پیام‌رسان ────────────────────────────────
 * پیش از این تنها راهِ گفتنِ «آسانسور خراب است» یک پیام در پیام‌رسان بود.
 * سه چیز را نداشت و هیچ‌کدام با افزودن ستون به `messages` درست نمی‌شد:
 *
 *   • **وضعیت.** پیام یا خوانده شده یا نشده؛ «در حال پیگیری» ندارد. مدیر
 *     برای فهمیدنِ اینکه چه چیزی هنوز باز است باید کلِ گفت‌وگو را می‌خواند.
 *   • **مسئول.** پیام گیرنده دارد ولی *متولی* ندارد. در ساختمان، تفاوتِ
 *     «همه دیدند» و «فلانی قرار است انجامش دهد» همه‌چیز است.
 *   • **پاسخگویی.** بدونِ زمانِ ثبت و زمانِ حل، هیچ‌وقت معلوم نمی‌شود
 *     پیگیری چقدر طول کشیده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complex_id')->constrained()->cascadeOnDelete();

            /*
             * درخواست به **واحد** بسته است، نه فقط به کاربر. اگر مستاجر
             * برود، سابقه‌ی «این واحد سه بار مشکلِ لوله داشته» باید بماند —
             * همان دلیلی که پیام‌رسان هم واحدمحور شد (R23a).
             */
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * مسئولِ پیگیری. کاربرِ همان مجتمع است و نه نقشِ تازه‌ای در
             * سامانه؛ مدیر می‌تواند به خودش، به مدیرِ دیگر، یا به ساکنی که
             * عملاً سرایدار/عضوِ هیئت‌مدیره است واگذار کند.
             */
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->string('category', 20);
            $table->string('priority', 12)->default('normal');
            $table->string('status', 12)->default('new');

            $table->string('title', 150);
            $table->text('description');

            // پیوست: همان الگوی خصوصیِ رسید و پیام (R19/R23b)
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();

            /*
             * دو مهرِ زمانی جدا، نه یکی: `resolved_at` می‌گوید مسئول چه
             * زمانی گفت انجام شد و `closed_at` می‌گوید ساکن چه زمانی
             * تاییدش کرد. فاصله‌شان همان چیزی است که نشان می‌دهد کارِ
             * اعلام‌شده واقعاً انجام شده یا نه.
             */
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['complex_id', 'status']);
            $table->index(['complex_id', 'assigned_to']);
            $table->index('unit_id');
        });

        /*
         * گفت‌وگوی درونِ درخواست.
         *
         * `is_internal` یادداشتِ مدیریتی است و به ساکن نشان داده نمی‌شود —
         * «به تاسیسات زنگ زدم، هفته‌ی بعد می‌آید» چیزی است که مدیر و مسئول
         * باید ببینند و ساکن لازم نیست. بدونِ آن، این حرف‌ها به تلگرام
         * می‌رفت و از پرونده‌ی درخواست بیرون می‌ماند.
         */
        Schema::create('service_request_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->timestamps();

            $table->index('service_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_request_comments');
        Schema::dropIfExists('service_requests');
    }
};
