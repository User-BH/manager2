<?php

namespace App\Services\Messenger;

use App\Exceptions\DomainException;
use App\Models\ComplexMembership;
use App\Models\Conversation;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * تعیین و برداشتنِ ناظرِ گروه — فقط مدیر (مرحلهٔ ۱۰ · فاز ۱۰؛ بندهای ۵۶ و ۵۷).
 *
 * ناظر عضوِ **فعالِ** همان گروه است (مالک یا مستأجر)؛ مدیر خودش همه‌ی اختیارات را دارد و ناظر نمی‌شود. ردیف به
 * عضویتِ همان لحظه بسته است: تکرار چیزی نمی‌سازد و پایانِ عضویت ناظری را هم تمام می‌کند.
 */
final class GroupModeration
{
    public function appoint(Conversation $conversation, User $member, User $by): void
    {
        $this->ensureGroup($conversation);

        $membership = DB::table('complex_memberships')
            ->where('complex_id', $conversation->complex_id)
            ->where('user_id', $member->id)
            ->where('status', ComplexMembership::ACTIVE)
            ->whereIn('role', ['owner', 'tenant'])
            ->first(['id']);

        $participant = $membership !== null
            && ConversationParticipants::query($conversation)->where('users.id', $member->id)->exists();

        if (! $participant) {
            throw DomainException::invalid('ناظر باید عضوِ فعالِ همین گروه باشد.', 'messenger.moderator_not_member', [
                'user_id' => ['ناظر باید عضوِ فعالِ همین گروه باشد.'],
            ]);
        }

        try {
            DB::table('conversation_moderators')->insertOrIgnore([
                'conversation_id' => $conversation->id, 'membership_id' => $membership->id, 'user_id' => $member->id,
                'appointed_by' => $by->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // تعیینِ هم‌زمانِ همان ناظر — یکی کافی است
        }

        Audit::log('messenger.moderator_appointed', 'تعیینِ ناظرِ گروه', $member, ['conversation_id' => $conversation->id]);
    }

    public function dismiss(Conversation $conversation, User $member): void
    {
        $this->ensureGroup($conversation);

        $removed = DB::table('conversation_moderators')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $member->id)
            ->delete();

        if ($removed > 0) {
            Audit::log('messenger.moderator_dismissed', 'برداشتنِ ناظرِ گروه', $member, ['conversation_id' => $conversation->id]);
        }
    }

    private function ensureGroup(Conversation $conversation): void
    {
        if (! in_array($conversation->type, [Conversation::CHANNEL, Conversation::GROUP], true)) {
            throw DomainException::invalid('ناظر فقط برای گروه است.', 'messenger.not_a_group');
        }
    }
}
