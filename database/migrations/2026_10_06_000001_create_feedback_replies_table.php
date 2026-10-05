<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * پاسخِ ادمینِ کل به بازخورد (مرحلهٔ ۸ · فاز ۶ · بندِ ۱۴).
 *
 * جدولِ جدا و نه ستونی روی `feedback`: هر پاسخ نویسنده، گیرنده و زمانِ خودش
 * را دارد و یک بازخورد می‌تواند بیش از یک پاسخ بگیرد. گیرنده همان لحظه‌ی
 * پاسخ ثبت می‌شود — اگر بعداً شماره صاحبِ دیگری پیدا کرد، سابقه عوض نمی‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_replies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feedback_id')->constrained('feedback')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['feedback_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_replies');
    }
};
