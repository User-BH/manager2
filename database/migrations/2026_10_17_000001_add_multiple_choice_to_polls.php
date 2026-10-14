<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * نظرسنجیِ چندگزینه‌ای (مرحلهٔ ۱۰ · فاز ۱۱؛ بندِ ۶۴ ِ فایلِ معماری).
 *
 * - `message_polls.max_selections`: ۱ یعنی تک‌گزینه‌ای (همه‌ی نظرسنجی‌های موجود)؛ بیشتر یعنی چندگزینه‌ای با سقف.
 * - یکتاییِ رأی از «(نظرسنجی، کاربر)» به «(نظرسنجی، کاربر، گزینه)»: هر گزینه یک ردیف، و همان گزینه دو بار نه.
 *
 * ⚠️ ترتیب برای MySQL است: کلیدِ خارجیِ `message_poll_id` به نمایه‌ای که با آن ستون شروع شود نیاز دارد؛ پس نمایه‌ی تازه
 * پیش از برداشتنِ نمایه‌ی قبلی ساخته می‌شود (و در `down()` برعکس).
 *
 * `down()`: نظرسنجی‌های چندگزینه‌ای به تک‌گزینه‌ای برمی‌گردند و از هر رأی‌دهنده فقط نخستین گزینه‌اش می‌ماند — ساختارِ
 * قبلی جای بیشتر از آن را نداشت. برگشت فقط گزینه‌های اضافیِ رأی‌های چندگزینه‌ای را پاک می‌کند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_polls', function (Blueprint $table) {
            $table->unsignedTinyInteger('max_selections')->default(1)->after('allow_change');
        });

        Schema::table('poll_votes', function (Blueprint $table) {
            $table->unique(['message_poll_id', 'user_id', 'poll_option_id']);
        });

        Schema::table('poll_votes', function (Blueprint $table) {
            $table->dropUnique(['message_poll_id', 'user_id']);
        });
    }

    public function down(): void
    {
        $keep = DB::table('poll_votes')->selectRaw('min(id) as id')->groupBy('message_poll_id', 'user_id')->pluck('id');
        DB::table('poll_votes')->whereNotIn('id', $keep)->delete();

        Schema::table('poll_votes', function (Blueprint $table) {
            $table->unique(['message_poll_id', 'user_id']);
        });

        Schema::table('poll_votes', function (Blueprint $table) {
            $table->dropUnique(['message_poll_id', 'user_id', 'poll_option_id']);
        });

        Schema::table('message_polls', function (Blueprint $table) {
            $table->dropColumn('max_selections');
        });
    }
};
