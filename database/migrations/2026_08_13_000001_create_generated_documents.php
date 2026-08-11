<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سندهای تولیدشده در صف (R28).
 *
 * ─── چرا این جدول لازم است ─────────────────────────────────────────────────
 * PDFِ تکیِ یک قبض در همان درخواست ساخته می‌شود و مشکلی ندارد. ولی «همه‌ی
 * قبض‌های دوره در یک فایل» برای مجتمعِ ۲۰۰ واحدی یعنی ۲۰۰ رندرِ HTML و یک
 * سندِ چندصد صفحه‌ای؛ در چرخه‌ی درخواست یا `max_execution_time` می‌خورد یا
 * سقفِ حافظه — و کاربر فقط یک ۵۰۰ می‌بیند.
 *
 * پس همان الگوی `backups` تکرار می‌شود: ردیف **پیش از** صف‌شدن با وضعیتِ
 * `pending` ساخته می‌شود تا کاربر بلافاصله ببیندش، و کارگر پُرش می‌کند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complex_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // نوعِ سند (`bills_bundle`، …) — فهرستِ بسته در `DocumentType`
            $table->string('type', 30);
            $table->string('title');

            // پارامترهای ساخت (دوره و…)، تا بازتولید ممکن بماند
            $table->json('params')->nullable();

            $table->string('status', 12)->default('pending');
            $table->string('path')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index(['complex_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_documents');
    }
};
