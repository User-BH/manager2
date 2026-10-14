<?php

namespace App\Services\Messenger;

use App\Models\ComplexMembership;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * این بیننده در این گفت‌وگو چه اختیاری دارد — ماتریسِ بندِ ۱۰۱ ِ فایلِ معماری، یک‌جا (مرحلهٔ ۱۰ · فاز ۱۰).
 *
 * | کار                      | عضو | مدیر | ناظرِ همین گروه |
 * | ------------------------ | --- | ---- | --------------- |
 * | حذفِ پیامِ دیگران در گروه | ✗   | ✓    | ✓               |
 * | سنجاق در گروه            | ✗   | ✓    | ✓               |
 * | سنجاق در گفت‌وگوی خصوصی   | ✓   | ✓    | —               |
 * | ساختِ نظرسنجی            | ✗   | ✓    | ✗ (`MessageSender`) |
 * | عضویت، واحد، مالی، دعوت  | ✗   | ✓    | ✗ (نقشِ عضویت؛ ناظر نقش نیست) |
 *
 * ⚠️ ناظر **نقشِ عضویت نیست** (بندِ ۱۶): هیچ میان‌افزار یا سیاستی آن را نمی‌شناسد جز همین کلاس، و این کلاس فقط درونِ
 * همان گفت‌وگو اختیار می‌دهد. ناظری به عضویتِ **فعالِ** همان مجتمع بسته است؛ معلق یا رفته ناظر نیست.
 */
final class ConversationRights
{
    private function __construct(
        public readonly bool $isModerator,
        /** حذفِ پیامِ دیگران (بندِ ۵۵) — فقط در گروه */
        public readonly bool $canDeleteOthers,
        /** سنجاق و برداشتنِ سنجاق (بندهای ۵۸ و ۵۹) */
        public readonly bool $canPin,
    ) {}

    public static function for(Conversation $conversation, User $viewer): self
    {
        $group = in_array($conversation->type, [Conversation::CHANNEL, Conversation::GROUP], true);
        $admin = $viewer->role->isAdmin();
        $moderator = $group && ! $admin && self::isModerator($conversation, $viewer);

        return new self(
            isModerator: $moderator,
            canDeleteOthers: $group && ($admin || $moderator),
            canPin: $group
                ? $admin || $moderator
                // خصوصی: هر دو طرف (بندِ ۵۹)؛ بایگانی و گفت‌وگوی بسته‌شده نه
                : $conversation->type !== Conversation::UNIT && $conversation->allowsPostingBy($viewer),
        );
    }

    public static function isModerator(Conversation $conversation, User $user): bool
    {
        return DB::table('conversation_moderators as m')
            ->join('complex_memberships as cm', 'cm.id', '=', 'm.membership_id')
            ->where('m.conversation_id', $conversation->id)
            ->where('m.user_id', $user->id)
            ->where('cm.complex_id', $conversation->complex_id)
            ->where('cm.status', ComplexMembership::ACTIVE)
            ->exists();
    }

    /**
     * شناسه‌ی ناظرانِ فعالِ این گفت‌وگو — برای فهرستِ اعضا، یک کوئری.
     *
     * @return list<int>
     */
    public static function moderatorIds(Conversation $conversation): array
    {
        return DB::table('conversation_moderators as m')
            ->join('complex_memberships as cm', 'cm.id', '=', 'm.membership_id')
            ->where('m.conversation_id', $conversation->id)
            ->where('cm.complex_id', $conversation->complex_id)
            ->where('cm.status', ComplexMembership::ACTIVE)
            ->pluck('m.user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
