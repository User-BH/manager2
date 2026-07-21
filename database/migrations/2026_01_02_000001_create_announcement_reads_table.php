<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * چه کسی کدام اطلاعیه را خوانده است.
 *
 * شمارنده‌ی زنگوله‌ی هدر از «اطلاعیه‌های قابل‌مشاهده منهای ردیف‌های اینجا»
 * به دست می‌آید، پس نبودِ ردیف یعنی نخوانده. با این کار حذف اطلاعیه هم
 * خودبه‌خود ردیف‌های خواندنش را پاک می‌کند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcement_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');

            // یک کاربر یک اطلاعیه را فقط یک‌بار «خوانده» می‌شود
            $table->unique(['announcement_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_reads');
    }
};
