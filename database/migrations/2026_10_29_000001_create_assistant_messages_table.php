<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاریخچه‌ی مکالمه‌ی دستیار (مرحلهٔ ۱۳ · فاز ۸؛ ایرادات ۱۱، بندِ ۴.۱۴).
 *
 * مالکیت: یک حساب **و** یک زمینه (`context_key` = مجتمع و نقش، یا سکو). پاسخِ حساس (تلفن، نامِ ساکنان) ذخیره نمی‌شود
 * (`answer` تهی، `redacted`). نگه‌داری ۳۰ روز (`assistant:prune`). جدولِ تازه — فقط افزودنی.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('complex_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('context_key', 40);
            $table->string('question', 300);
            $table->text('answer')->nullable();
            $table->string('status', 16);
            $table->string('tool', 40)->nullable();
            $table->boolean('redacted')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'context_key', 'id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
    }
};
