<?php

namespace App\Services\Account;

use App\Enums\UserRole;
use App\Exceptions\DomainException;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\ComplexMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * دفترِ عضویت — **تنها** جایی که عضویت برقرار، پایان یا نقشش عوض می‌شود
 * (مرحلهٔ ۱۰ · فاز ۲؛ بندهای ۲، ۱۱ و ۷۴ ِ فایلِ معماری).
 *
 * ─── دو منبع، یک نویسنده ────────────────────────────────────────────────────
 * `complex_memberships` عضویت‌ها و تاریخچه‌شان است. `users.complex_id` و `users.role`
 * تا ۱۰/۳ «زمینه‌ی فعال» ِ حساب‌اند و مجوز هنوز از آن‌ها خوانده می‌شود. دو جا یعنی
 * خطرِ ناهم‌خوانی (بندِ ۲)؛ پس هر دو فقط از همین کلاس و در یک تراکنش عوض می‌شوند،
 * و `User` تغییرِ این دو ستون را بیرون از این‌جا رد می‌کند. `violations()` هر
 * ناهم‌خوانیِ باقی‌مانده را گزارش می‌کند (`memberships:check`).
 *
 * ─── قاعده‌ی امروز ──────────────────────────────────────────────────────────
 * یک عضویتِ فعال برای هر حساب. schema چند عضویتِ فعال در مجتمع‌های مختلف را
 * می‌پذیرد؛ برداشتنِ این قاعده با زنجیره‌ی مجوزِ ۱۰/۳ است، نه پیش از آن.
 */
class MembershipLedger
{
    private static int $writing = 0;

    /** آیا تغییرِ `complex_id` / `role` ِ حساب الان از همین دفتر است؟ */
    public static function isWriting(): bool
    {
        return self::$writing > 0;
    }

    /**
     * برقراریِ عضویت. فراخوان پیش از این حساب را در حالتِ اولیه دیده است؛ این‌جا
     * زیرِ قفلِ حساب دوباره سنجیده می‌شود.
     */
    public function start(User $user, Complex $complex, UserRole $role, string $source, ?ComplexInvitation $via = null): ComplexMembership
    {
        if (! in_array($role, ComplexMembership::ROLES, true)) {
            throw DomainException::invalid('این نقش عضویتِ مجتمع نیست.', 'membership.invalid_role');
        }

        return DB::transaction(function () use ($user, $complex, $role, $source, $via): ComplexMembership {
            $account = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($user->id);

            if ($account->complex_id !== null || $this->activeOf($account)->exists()) {
                throw DomainException::invalid('شما از قبل عضو یک مجتمع هستید.', 'account.already_member');
            }

            $membership = (new ComplexMembership)->forceFill([
                'user_id' => $account->id,
                'complex_id' => $complex->id,
                'role' => $role,
                'status' => ComplexMembership::ACTIVE,
                'source' => $source,
                'invitation_id' => $via?->id,
                'started_at' => now(),
                'active_key' => ComplexMembership::activeKey($account->id, $complex->id),
            ]);
            $membership->save();

            $this->writeAccount($account, ['complex_id' => $complex->id, 'role' => $role]);
            $user->setRawAttributes($account->getAttributes(), true);

            return $membership;
        }, attempts: 3);
    }

    /**
     * پایانِ عضویت — نه حذفِ حساب (بندِ ۷۴). ردیف می‌ماند با زمانِ پایان، پایان‌دهنده
     * و دلیل؛ حساب به حالتِ اولیه برمی‌گردد.
     */
    public function end(User $user, string $reason, ?User $by = null): void
    {
        DB::transaction(function () use ($user, $reason, $by): void {
            $account = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($user->id);

            $this->close($account, $reason, $by);

            // نقشِ حسابِ اولیه همان است که ثبت‌نام می‌دهد (`AuthController::registerVerify`)
            $this->writeAccount($account, ['complex_id' => null, 'role' => UserRole::Owner]);
            $user->setRawAttributes($account->getAttributes(), true);
        }, attempts: 3);
    }

    /**
     * تغییرِ نقش. درونِ یک مجتمع همان عضویت نقشِ تازه می‌گیرد؛ ارتقا به ادمینِ کل
     * عضویت را با دلیلِ «promoted» می‌بندد (نقشِ سکو در هیچ مجتمعی عضو نیست).
     */
    public function changeRole(User $user, UserRole $role, ?User $by = null): void
    {
        DB::transaction(function () use ($user, $role, $by): void {
            $account = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($user->id);

            if ($role === UserRole::SuperAdmin) {
                $this->close($account, ComplexMembership::END_PROMOTED, $by);
                $this->writeAccount($account, ['complex_id' => null, 'role' => $role]);
            } elseif ($account->complex_id !== null) {
                $this->activeOf($account)->where('complex_id', $account->complex_id)->update(['role' => $role->value, 'updated_at' => now()]);
                $this->writeAccount($account, ['role' => $role]);
            } else {
                // حسابِ بی‌مجتمع (اولیه یا ادمینِ کلِ پیشین): عضویتی نیست که عوض شود
                $this->writeAccount($account, ['role' => $role]);
            }

            $user->setRawAttributes($account->getAttributes(), true);
        }, attempts: 3);
    }

    /**
     * حسابی که از ابتدا درونِ مجتمع ساخته شد (دادهٔ نمونه، ابزارِ آزمون) عضوِ همان
     * مجتمع است. ثبت‌نام و ادمینِ کل بی‌مجتمع ساخته می‌شوند و این‌جا کاری ندارند.
     */
    public function recordCreated(User $user): void
    {
        if ($user->complex_id === null || ! in_array($user->role, ComplexMembership::ROLES, true)) {
            return;
        }

        (new ComplexMembership)->forceFill([
            'user_id' => $user->id,
            'complex_id' => $user->complex_id,
            'role' => $user->role,
            'status' => ComplexMembership::ACTIVE,
            'source' => ComplexMembership::SOURCE_DIRECT,
            'started_at' => now(),
            'active_key' => ComplexMembership::activeKey($user->id, $user->complex_id),
        ])->save();
    }

    /**
     * هر ناهم‌خوانیِ میانِ حساب و عضویت؛ خالی یعنی سالم.
     *
     * @return list<string>
     */
    public function violations(): array
    {
        $problems = [];

        $members = User::withoutGlobalScopes()
            ->whereNotNull('complex_id')
            ->whereIn('role', array_map(fn (UserRole $r) => $r->value, ComplexMembership::ROLES))
            ->get(['id', 'complex_id', 'role']);

        $active = ComplexMembership::withoutGlobalScopes()->active()->get(['id', 'user_id', 'complex_id', 'role'])->groupBy('user_id');

        foreach ($members as $member) {
            $mine = $active->get($member->id, collect());
            $match = $mine->first(fn (ComplexMembership $m) => $m->complex_id === $member->complex_id && $m->role === $member->role);

            if ($match === null) {
                $problems[] = "حسابِ {$member->id} در مجتمعِ {$member->complex_id} با نقشِ {$member->role->value} است ولی عضویتِ فعالِ هم‌خوان ندارد.";
            }
        }

        $memberIds = $members->pluck('complex_id', 'id');
        foreach ($active as $userId => $rows) {
            if ($rows->count() > 1) {
                $problems[] = "حسابِ {$userId} بیش از یک عضویتِ فعال دارد (تا ۱۰/۳ مجاز نیست).";
            }
            if (! $memberIds->has($userId)) {
                $problems[] = "حسابِ {$userId} عضویتِ فعال دارد ولی زمینه‌ی فعالِ حساب خالی یا نقشِ سکوست.";
            }
        }

        return $problems;
    }

    /** @return Builder<ComplexMembership> */
    private function activeOf(User $account)
    {
        return ComplexMembership::withoutGlobalScopes()->active()->where('user_id', $account->id);
    }

    private function close(User $account, string $reason, ?User $by): void
    {
        $this->activeOf($account)->update([
            'status' => ComplexMembership::ENDED,
            'ended_at' => now(),
            'ended_by' => $by?->id,
            'end_reason' => $reason,
            'active_key' => null,
            'updated_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $changes */
    private function writeAccount(User $account, array $changes): void
    {
        self::$writing++;

        try {
            $account->forceFill($changes)->save();
        } finally {
            self::$writing--;
        }
    }
}
