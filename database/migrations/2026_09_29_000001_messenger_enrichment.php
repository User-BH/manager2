<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * غنی‌سازیِ پیام‌رسان (مرحلهٔ ۶ · فاز ۱۴) — فقط افزودنی (Expand).
 *
 * پاسخ، ویرایش، حذف، سنجاق، واکنش و گزارش. پیامِ صوتی ستونِ تازه نمی‌خواهد:
 * همان پیوست است با نوعِ `audio`.
 *
 * ⚠️ `deleted_at` اینجا **SoftDeletes نیست**: ردیف در گفت‌وگو می‌ماند تا
 * ترتیب، پاسخ‌ها و رسیدها نشکنند و همه «این پیام حذف شد» ببینند؛ ولی متن و
 * پیوستش همان لحظه پاک می‌شوند. SoftDeletes ردیف را از هر پرسش پنهان می‌کرد
 * و متن را نگه می‌داشت — درست برعکسِ آنچه حذف باید بکند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('reply_to_id')->nullable()->after('conversation_id')
                ->constrained('messages')->nullOnDelete();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::table('conversations', function (Blueprint $table) {
            // بی‌کلیدِ خارجی عمداً: پیام و گفت‌وگو هم‌دیگر را ارجاع می‌دهند؛ درستیِ
            // «پیامِ همین گفت‌وگو» را کد می‌سنجد
            $table->unsignedBigInteger('pinned_message_id')->nullable();
        });

        Schema::create('message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('emoji', 16);
            $table->timestamp('created_at')->useCurrent();

            // هر کس روی هر پیام یک واکنش؛ دوباره‌زدن عوضش می‌کند
            $table->unique(['message_id', 'user_id']);
        });

        Schema::create('message_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complex_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 300)->nullable();
            $table->string('resolution', 20)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // هر کس هر پیام را یک بار گزارش می‌کند
            $table->unique(['message_id', 'user_id']);
            $table->index(['complex_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_reports');
        Schema::dropIfExists('message_reactions');

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('pinned_message_id');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reply_to_id');
            $table->dropColumn(['edited_at', 'deleted_at']);
        });
    }
};
