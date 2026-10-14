<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ناظرِ گروه، چند سنجاق و هدایت (مرحلهٔ ۱۰ · فاز ۱۰؛ بندهای ۱۶، ۵۲، ۵۵ تا ۵۹ ِ فایلِ معماری).
 *
 * - `conversation_moderators`: ناظر به **عضویت** بسته است، نه به حساب — عضویتی که پایان یابد ناظری‌اش هم تمام است، و
 *   بازگشتِ همان شخص با عضویتِ تازه ناظری را برنمی‌گرداند. فقط مدیر تعیین می‌کند (`appointed_by`).
 * - `conversation_pins`: «Pinned messages» ِ بندِ ۵۸ جمع است. سنجاقِ موجود منتقل و ستونِ تکیِ `pinned_message_id` تهی
 *   می‌شود — دو منبع نمی‌ماند و هیچ کدی آن را دیگر نمی‌خواند. ستون عمداً **حذف نمی‌شود**: حذفش روی SQLite جای ستون‌ها را
 *   عوض می‌کرد و `down()` جدول را دقیقاً برنمی‌گرداند (`MigrationSafetyTest`).
 * - `messages.deleted_by`: حذفِ پیامِ دیگران به دستِ مدیر یا ناظر (بندِ ۵۵) با نامِ حذف‌کننده ثبت می‌شود.
 * - `messages.forwarded_from_id` / `forwarded_author_name`: هدایت نسخه‌ای جدا در گفت‌وگوی مقصد است (بندِ ۵۳) — پیامِ
 *   مبدأ حذف شود، نسخه می‌ماند؛ نامِ نویسنده‌ی مبدأ همان لحظه ثبت می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_moderators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained('complex_memberships')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['conversation_id', 'membership_id']);
            $table->index(['user_id', 'conversation_id']);
        });

        Schema::create('conversation_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pinned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['conversation_id', 'message_id']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('deleted_by')->nullable()->after('deleted_at')->constrained('users')->nullOnDelete();
            $table->foreignId('forwarded_from_id')->nullable()->after('reply_to_id')->constrained('messages')->nullOnDelete();
            $table->string('forwarded_author_name', 120)->nullable()->after('forwarded_from_id');
        });

        foreach (DB::table('conversations')->whereNotNull('pinned_message_id')->get(['id', 'pinned_message_id']) as $row) {
            if (DB::table('messages')->where('id', $row->pinned_message_id)->exists()) {
                DB::table('conversation_pins')->insert([
                    'conversation_id' => $row->id, 'message_id' => $row->pinned_message_id, 'created_at' => now(),
                ]);
            }
        }

        DB::table('conversations')->whereNotNull('pinned_message_id')->update(['pinned_message_id' => null]);
    }

    public function down(): void
    {
        // ساختارِ قبلی یک سنجاق داشت: تازه‌ترین می‌ماند
        foreach (DB::table('conversation_pins')->orderBy('id')->get(['conversation_id', 'message_id']) as $pin) {
            DB::table('conversations')->where('id', $pin->conversation_id)->update(['pinned_message_id' => $pin->message_id]);
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['forwarded_from_id']);
            $table->dropForeign(['deleted_by']);
            $table->dropColumn(['forwarded_from_id', 'forwarded_author_name', 'deleted_by']);
        });

        Schema::dropIfExists('conversation_pins');
        Schema::dropIfExists('conversation_moderators');
    }
};
