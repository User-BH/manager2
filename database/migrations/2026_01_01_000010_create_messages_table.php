<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// پیام‌رسان داخلی مجتمع. فقط متن. متادیتای نویسنده برای حفظ سابقه ذخیره می‌شود.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complex_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->text('body'); // فقط متن

            // متادیتای نمایش (denormalized تا اگر کاربر حذف/جابه‌جا شد سابقه بماند)
            $table->string('author_name')->nullable();
            $table->string('author_role', 30)->nullable();
            $table->string('unit_label')->nullable(); // مثلا «واحد ۱۲ - طبقه ۳»

            $table->boolean('is_hidden')->default(false);
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['complex_id', 'created_at']);
        });

        Schema::create('message_restrictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complex_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(['complex_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_restrictions');
        Schema::dropIfExists('messages');
    }
};
