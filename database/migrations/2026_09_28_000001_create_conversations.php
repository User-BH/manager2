<?php

use App\Services\Messenger\ConversationBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * پیام‌رسانِ گفت‌وگومحور (مرحلهٔ ۶ · فاز ۱۲) — گامِ Expand و Migrate.
 *
 * ─── چه چیزی عوض می‌شود ───────────────────────────────────────────────────
 * `conversations` تازه است و `messages.conversation_id` تهی‌پذیر اضافه می‌شود؛
 * سپس هر پیامِ موجود گفت‌وگوی خودش را می‌گیرد (`ConversationBackfill`).
 *
 * ─── چه چیزی عوض **نمی‌شود** ────────────────────────────────────────────────
 * ⚠️ هیچ ستون یا جدولی حذف نمی‌شود و هیچ پیامی تغییر نمی‌کند. `audience`،
 * `unit_id`، `message_units` و `message_reads` می‌مانند و کدِ تازه همچنان در
 * آن‌ها هم می‌نویسد — پس برگشت به نسخه‌ی قبل بی‌خطر است. حذفشان گامِ
 * Contract است و طبقِ `MIGRATIONS.md` دستِ‌کم یک استقرارِ کامل بعد.
 *
 * `key` یکتایی را در خودِ دیتابیس نگه می‌دارد: یک کانال در هر مجتمع، یک رشته
 * برای هر واحد — حتی اگر دو درخواستِ هم‌زمان بخواهند بسازندش.
 *
 * `client_id` پیامِ تکراری را می‌گیرد: کلاینتی که ارسال را پس از قطعی دوباره
 * می‌فرستد همان پیام را پس می‌گیرد، نه پیامِ دوم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complex_id')->constrained()->cascadeOnDelete();
            // channel | unit | user | group
            $table->string('type', 20);
            $table->string('key', 80);
            $table->string('title', 120)->nullable();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('members_can_post')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // بی‌کلیدِ خارجی: پیام به گفت‌وگو اشاره می‌کند و برعکسش حلقه می‌ساخت
            $table->unsignedBigInteger('last_message_id')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['complex_id', 'key']);
            $table->index(['complex_id', 'last_message_id']);
        });

        Schema::create('conversation_units', function (Blueprint $table) {
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->primary(['conversation_id', 'unit_id']);
            $table->index('unit_id');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('conversation_id')->nullable()->after('complex_id')
                ->constrained()->nullOnDelete();
            $table->string('client_id', 40)->nullable()->after('user_id');

            $table->index(['conversation_id', 'id']);
            $table->unique(['user_id', 'client_id']);
        });

        app(ConversationBackfill::class)->run();
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'client_id']);
            $table->dropIndex(['conversation_id', 'id']);
            $table->dropConstrainedForeignId('conversation_id');
            $table->dropColumn('client_id');
        });

        Schema::dropIfExists('conversation_units');
        Schema::dropIfExists('conversations');
    }
};
