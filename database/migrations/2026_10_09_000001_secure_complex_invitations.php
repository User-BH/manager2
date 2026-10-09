<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * دعوتِ امن (مرحلهٔ ۹ · فاز ۶؛ F5 و F7 ِ Audit ِ ۹/۲).
 *
 * پیش از این دعوت فقط برای **حسابِ موجود** بود و نه توکن داشت نه انقضا؛ برای
 * شماره‌ی بی‌حساب، مدیر خودش حساب می‌ساخت و رمزش را تعیین می‌کرد (F5).
 *
 * • `phone` — دعوت به **شماره** بسته است، نه به حساب؛ گیرنده شاید هنوز حساب
 *   ندارد. پس `user_id` اختیاری می‌شود و فقط هنگامِ پذیرش پر می‌شود.
 * • `token_hash` — فقط SHA-256 ِ توکن؛ خودِ توکن یک بار به دعوت‌کننده نشان
 *   داده می‌شود و هیچ‌جا ذخیره نمی‌شود.
 * • `expires_at` — دعوتِ بی‌انقضا پذیرفته نیست (F7).
 * • `live_key` — «یک دعوتِ زنده برای هر (مجتمع، شماره)» در خودِ پایگاه‌داده:
 *   تا وقتی در انتظار است پر و یکتاست، با پاسخ یا ابطال `NULL` می‌شود. ایندکسِ
 *   جزئی (`WHERE status = 'pending'`) در MySQL نیست؛ `NULL` در هر دو موتور
 *   با هم برخورد نمی‌کند.
 *
 * ─── 🔴 چرا یکتاییِ قبلی برداشته شد ──────────────────────────────────────
 * `(complex_id, user_id, status)` یعنی یک کاربر برای هر مجتمع فقط **یک**
 * دعوتِ ردشده یا پذیرفته‌شده می‌توانست داشته باشد: ردِ دومین دعوتِ همان مجتمع
 * خطای یکتایی (۵۰۰) می‌داد، و با F4 (پایانِ عضویت بی حذفِ حساب) بازگشتِ
 * همان ساکن به همان مجتمع هم. `live_key` همان حفاظت را فقط برای «در انتظار»
 * نگه می‌دارد.
 *
 * @destructive فقط `user_id` اختیاری می‌شود (`->change()`؛ در SQLite یعنی
 * بازسازیِ جدول با همان داده). هیچ ردیفی حذف یا خالی نمی‌شود، و `down` اگر
 * دعوتِ بی‌حساب ببیند می‌ایستد به‌جای اینکه داده را دور بریزد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complex_invitations', function (Blueprint $table) {
            $table->dropUnique('invitation_unique_pending');
            $table->dropForeign(['user_id']);
        });

        Schema::table('complex_invitations', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->string('phone', 11)->nullable()->after('user_id');
            $table->char('token_hash', 64)->nullable()->unique()->after('phone');
            $table->timestamp('expires_at')->nullable()->after('status');
            $table->string('live_key', 64)->nullable()->unique()->after('expires_at');

            // «دعوت‌های من» حالا با شماره خوانده می‌شود
            $table->index(['phone', 'status']);
        });

        // ردیف‌های موجود (production خالی است؛ برای هر نصبِ دیگر): شماره از حساب
        DB::table('complex_invitations')
            ->whereNull('phone')
            ->whereNotNull('user_id')
            ->update(['phone' => DB::raw('(select phone from users where users.id = complex_invitations.user_id)')]);
    }

    public function down(): void
    {
        $accountless = DB::table('complex_invitations')->whereNull('user_id')->count();
        if ($accountless > 0) {
            throw new RuntimeException(
                "{$accountless} دعوت هنوز حساب ندارد؛ بازگشت به user_id ِ اجباری داده را دور می‌ریخت. هیچ داده‌ای تغییر نکرد.",
            );
        }

        Schema::table('complex_invitations', function (Blueprint $table) {
            $table->dropIndex(['phone', 'status']);
            $table->dropUnique(['token_hash']);
            $table->dropUnique(['live_key']);
            $table->dropColumn(['phone', 'token_hash', 'expires_at', 'live_key']);
        });

        /*
         * `unit_id` و `invited_by` هم برداشته و **پس از** `user_id` دوباره گذاشته
         * می‌شوند — همان معنا؛ فقط تا ترتیبِ کلیدها دقیقاً مثلِ پیش از `up` شود.
         */
        Schema::table('complex_invitations', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['unit_id']);
            $table->dropForeign(['invited_by']);
        });

        Schema::table('complex_invitations', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('unit_id')->references('id')->on('units')->nullOnDelete();
            $table->foreign('invited_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['complex_id', 'user_id', 'status'], 'invitation_unique_pending');
        });
    }
};
