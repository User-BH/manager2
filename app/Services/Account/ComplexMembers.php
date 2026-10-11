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
    public static function query(int $complexId, array $roles): Builder
    {
        return User::query()
            ->join('complex_memberships as cm', function ($join) use ($complexId): void {
                $join->on('cm.user_id', '=', 'users.id')
                    ->where('cm.complex_id', $complexId)
                    ->where('cm.status', ComplexMembership::ACTIVE);
            })
            ->whereIn('cm.role', self::values($roles))
            ->select('users.*', 'cm.role as membership_role');
    }

    /** @param  list<UserRole>  $roles */
    public static function has(User $user, int $complexId, array $roles): bool
    {
        return self::active()
            ->where('user_id', $user->id)
            ->where('complex_id', $complexId)
            ->whereIn('role', self::values($roles))
            ->exists();
    }

    public static function roleOf(User $user, int $complexId): ?UserRole
    {
        $role = self::active()->where('user_id', $user->id)->where('complex_id', $complexId)->value('role');

        return $role === null ? null : UserRole::from((string) $role);
    }

    /** نقشِ عضو در مجتمعی که از آن خوانده شد (`query`)، وگرنه نقشِ زمینه‌ی فعال. */
    public static function displayedRole(User $user): UserRole
    {
        $role = $user->getAttribute('membership_role');

        return $role === null ? $user->role : UserRole::from((string) $role);
    }

    /** @return \Illuminate\Database\Query\Builder */
    private static function active()
    {
        return DB::table('complex_memberships')->where('status', ComplexMembership::ACTIVE);
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
