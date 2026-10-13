<?php

namespace App\Services\Account;

use App\Enums\UserRole;
use App\Models\ComplexMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * «اعضای مجتمعِ X با نقشِ Y» — از عضویت، نه از زمینه‌ی فعالِ حساب (مرحلهٔ ۱۰ · فاز ۳).
 *
 * از ۱۰/۳ یک حساب می‌تواند در چند مجتمع عضو باشد و `users.complex_id` فقط مجتمعی است
 * که **الان** در آن کار می‌کند. پس فهرستِ ساکنان، مدیران، جست‌وجو، مخاطبانِ مدیریت و
 * سیاست‌ها باید عضویت را بخوانند: عضوِ مجتمعِ ب که الان در الف است همچنان ساکنِ ب است،
 * و نقشش در ب همان نقشِ عضویتِ ب است، نه نقشِ زمینه‌ی فعالش.
 *
 * عمداً از `DB::table` می‌خواند، نه از مدلِ `ComplexMembership`: آن مدل دامنه‌ی مستأجر
 * دارد و این‌جا مجتمع صریحاً داده شده است (مثلاً اعلانِ مدیرانِ مجتمع در درخواستی که
 * زمینه‌اش حسابِ اولیه است).
 */
final class ComplexMembers
{
    /**
     * کاربرانِ عضوِ فعالِ مجتمع با این نقش‌ها؛ نقشِ عضویت در ستونِ `membership_role`.
     *
     * ⚠️ پس از پیوند، ستونِ `role` و `id` مبهم‌اند: صافی‌ها باید `cm.role` و `users.id`
     * بنویسند.
     *
     * @param  list<UserRole>  $roles
     * @return Builder<User>
     */
    public static function query(int $complexId, array $roles, bool $withSuspended = false): Builder
    {
        $statuses = $withSuspended ? ComplexMembership::LIVE : [ComplexMembership::ACTIVE];

        return User::query()
            ->join('complex_memberships as cm', function ($join) use ($complexId, $statuses): void {
                $join->on('cm.user_id', '=', 'users.id')
                    ->where('cm.complex_id', $complexId)
                    ->whereIn('cm.status', $statuses);
            })
            ->whereIn('cm.role', self::values($roles))
            ->select('users.*', 'cm.role as membership_role', 'cm.status as membership_status');
    }

    /** @param  list<UserRole>  $roles */
    public static function has(User $user, int $complexId, array $roles, bool $withSuspended = false): bool
    {
        return self::statuses($withSuspended)
            ->where('user_id', $user->id)
            ->where('complex_id', $complexId)
            ->whereIn('role', self::values($roles))
            ->exists();
    }

    public static function roleOf(User $user, int $complexId, bool $withSuspended = false): ?UserRole
    {
        $role = self::statuses($withSuspended)->where('user_id', $user->id)->where('complex_id', $complexId)->value('role');

        return $role === null ? null : UserRole::from((string) $role);
    }

    /** نقشِ عضو در مجتمعی که از آن خوانده شد (`query`)، وگرنه نقشِ زمینه‌ی فعال. */
    public static function displayedRole(User $user): UserRole
    {
        $role = $user->getAttribute('membership_role');

        return $role === null ? $user->role : UserRole::from((string) $role);
    }

    /** @return \Illuminate\Database\Query\Builder */
    /** وضعیتِ عضویتِ زنده‌ی این حساب در این مجتمع — `active`، `suspended` یا null (۱۰/۶) */
    public static function statusOf(User $user, int $complexId): ?string
    {
        $status = self::statuses(true)->where('user_id', $user->id)->where('complex_id', $complexId)->value('status');

        return $status === null ? null : (string) $status;
    }

    /**
     * عضویت‌های فعال — یا زنده (فعال و معلق) وقتی `$withSuspended`. پیش‌فرض همان فعال است: مجوز، اعلان و شمارشِ
     * مدیران فقط عضوِ فعال را می‌شناسند؛ «از قبل عضو است» و مدیریتِ عضو، معلق را هم.
     */
    private static function statuses(bool $withSuspended)
    {
        return DB::table('complex_memberships')
            ->whereIn('status', $withSuspended ? ComplexMembership::LIVE : [ComplexMembership::ACTIVE]);
    }

    /**
     * @param  list<UserRole>  $roles
     * @return list<string>
     */
    private static function values(array $roles): array
    {
        return array_map(fn (UserRole $role) => $role->value, $roles);
    }
}
