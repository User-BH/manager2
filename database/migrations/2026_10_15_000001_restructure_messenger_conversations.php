<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ساختارِ پیام‌رسان (مرحلهٔ ۱۰ · فاز ۹؛ بندهای ۳۱ تا ۴۰، ۷۰ و ۸۰ ِ فایلِ معماری).
 *
 * ─── گفت‌وگوها ───────────────────────────────────────────────────────────────
 * - `peer_id`: طرفِ دومِ گفت‌وگوی «مالک ↔ مستأجر» (`user_id` مالک، `peer_id` مستأجر). گفت‌وگو مالِ همین دو نفر
 *   در همین واحد است: مستأجرِ تازه گفت‌وگوی تازه می‌گیرد و تاریخچه‌ی قبلی جدا می‌ماند (بندِ ۷۲).
 * - کانالِ «همه‌ی اهالی» گروهِ اصلیِ مجتمع می‌شود (بندِ ۳۲): اعضا در آن می‌نویسند — فایل ویرایش و حذفِ پیامِ خودِ
 *   «هر member» را در گروه می‌خواهد (بندهای ۵۴ و ۵۵). مدیر همچنان می‌تواند نوشتن را فقط برای مدیریت بگذارد.
 *
 * ─── بستنِ پیام‌رسان روی عضویت، نه حساب (یافته‌ی ۱۰/۶) ─────────────────────────
 * `users.can_message` ستونِ **حساب** بود: مدیرِ مجتمعِ الف پیام‌رسانِ همان شخص را در مجتمعِ ب هم می‌بست — همان
 * خانواده‌ی `is_active` که ۱۰/۶ بست. حالا `complex_memberships.messaging_blocked_at`. دادهٔ موجود: اگر آخرین
 * رویدادِ ثبت‌شده «resident.messaging_blocked» ِ مدیری است، فقط در همان مجتمع؛ بی ردِ audit، در همه‌ی عضویت‌های
 * زنده (همان اثرِ امروز — چیزی بی‌صدا باز نمی‌شود). سپس ستونِ حساب به حالتِ پیش‌فرض برمی‌گردد تا منبعِ دومی نماند.
 *
 * ⚠️ `messaging_blocked_by` عمداً نیست: افزودنِ کلیدِ خارجی روی SQLite جدولِ عضویت را از نو می‌سازد و قیدهای ۱۰/۲ و
 * ۱۰/۶ را می‌اندازد؛ بستن‌کننده در `audit_logs` هست.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('peer_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->index(['complex_id', 'type']);
        });

        Schema::table('complex_memberships', function (Blueprint $table) {
            $table->timestamp('messaging_blocked_at')->nullable()->after('suspended_by');
        });

        DB::table('conversations')->where('type', 'channel')->update(['members_can_post' => true]);

        $this->moveMessagingBlocks();
    }

    public function down(): void
    {
        $blocked = DB::table('complex_memberships')->whereNotNull('messaging_blocked_at')->pluck('user_id')->unique();
        DB::table('users')->whereIn('id', $blocked)->update(['can_message' => false]);

        DB::table('conversations')->where('type', 'channel')->update(['members_can_post' => false]);
        /*
         * 🔴 گفت‌وگوهای خصوصیِ این فاز در ساختارِ قبلی جایی ندارند: کدِ پیش از ۱۰/۹ هر پیامی را به مدیر نشان می‌دهد.
         * پیامِ بی‌گفت‌وگو مانده با مخاطبِ «management» برای مدیر خوانا می‌شد — یادداشتِ «ذخیره‌شده» و گفت‌وگوی مالک
         * و مستأجر جلوی مدیر. پس در برگشت پیام‌هایشان هم می‌روند: از دست رفتنِ یادداشت در بازگشتِ اضطراری بهتر از
         * فاش شدنِ آن است.
         */
        $private = DB::table('conversations')->whereIn('type', ['pair', 'saved'])->pluck('id');
        DB::table('messages')->whereIn('conversation_id', $private)->delete();
        DB::table('conversations')->whereIn('id', $private)->delete();

        Schema::table('complex_memberships', function (Blueprint $table) {
            $table->dropColumn('messaging_blocked_at');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['complex_id', 'type']);
            $table->dropForeign(['peer_id']);
            $table->dropColumn('peer_id');
        });
    }

    private function moveMessagingBlocks(): void
    {
        foreach (DB::table('users')->where('can_message', false)->pluck('id') as $userId) {
            $last = DB::table('audit_logs')
                ->where('subject_type', 'App\\Models\\User')
                ->where('subject_id', $userId)
                ->whereIn('action', ['resident.messaging_blocked', 'resident.messaging_allowed'])
                ->orderByDesc('created_at')->orderByDesc('id')
                ->first(['action', 'complex_id', 'created_at']);

            DB::table('complex_memberships')
                ->where('user_id', $userId)
                ->whereIn('status', ['active', 'suspended'])
                ->when(
                    $last?->action === 'resident.messaging_blocked' && $last->complex_id !== null,
                    fn ($q) => $q->where('complex_id', $last->complex_id),
                )
                ->update(['messaging_blocked_at' => $last->created_at ?? now(), 'updated_at' => now()]);

            DB::table('users')->where('id', $userId)->update(['can_message' => true]);
        }
    }
};
