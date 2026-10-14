<?php

namespace App\Services\Messenger;

use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Account\ComplexMembers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * اعضای یک گروه — **از عضویتِ فعال**، هر بار (مرحلهٔ ۱۰ · فاز ۹؛ بندهای ۳۵، ۳۶، ۷۰ و ۸۰ ِ فایلِ معماری).
 *
 * «ConversationMember» ِ مدلِ مفهومیِ بندِ ۳ این‌جا جدولِ جدا نیست: اعضای گروه همان اعضای فعالِ مجتمع‌اند (گروهِ
 * مجتمع) یا ساکنانِ فعلیِ واحدهای انتخابی به‌علاوه‌ی مدیریت (گروهِ مدیر). جدولِ همگام‌شونده منبعِ دوم می‌شد و با
 * هر پایان، تعلیق یا جابه‌جاییِ دوره باید به‌روز می‌شد؛ پایانِ عضویت که دسترسی را می‌بندد (بندِ ۳۴، ۱۰/۶) شمار را هم
 * همان لحظه کم می‌کند. عضوِ معلق و رفته در شمار نیست (بندِ ۷۰).
 */
final class ConversationParticipants
{
    private const ROLES = [UserRole::Owner, UserRole::Tenant, UserRole::ComplexAdmin];

    /** @return Builder<User> */
    public static function query(Conversation $conversation): Builder
    {
        $query = ComplexMembers::query($conversation->complex_id, self::ROLES);

        if ($conversation->type === Conversation::GROUP) {
            $query->where(fn ($q) => $q->where('cm.role', UserRole::ComplexAdmin->value)
                ->orWhereIn('users.id', DB::table('unit_user')
                    ->select('unit_user.user_id')
                    ->where('unit_user.complex_id', $conversation->complex_id)
                    ->where('unit_user.is_current', true)
                    ->whereIn('unit_user.unit_id', DB::table('conversation_units')
                        ->select('unit_id')
                        ->where('conversation_id', $conversation->id))));
        }

        return $query;
    }

    /** @return array{members: int, owners: int, tenants: int, managers: int} */
    public static function counts(Conversation $conversation): array
    {
        $byRole = self::query($conversation)->toBase()
            ->select('cm.role')
            ->selectRaw('count(*) as total')
            ->groupBy('cm.role')
            ->pluck('total', 'role')
            ->map(fn ($n) => (int) $n);

        return [
            'members' => (int) $byRole->sum(),
            'owners' => $byRole->get(UserRole::Owner->value, 0),
            'tenants' => $byRole->get(UserRole::Tenant->value, 0),
            'managers' => $byRole->get(UserRole::ComplexAdmin->value, 0),
        ];
    }
}
