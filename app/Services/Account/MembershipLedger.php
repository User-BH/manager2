<?php

namespace App\Services\Account;

use App\Enums\UserRole;
use App\Exceptions\DomainException;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\ComplexMembership;
use App\Models\User;
use App\Support\SessionContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * دفترِ عضویت — **تنها** جایی که عضویت برقرار، پایان یا نقشش عوض می‌شود، و زمینه‌ی
 * فعالِ حساب جابه‌جا (مرحلهٔ ۱۰ · فاز ۲ و ۳؛ بندهای ۲، ۱۱، ۱۳، ۱۴، ۷۴ و ۹۲ ِ فایلِ معماری).
 *
 * ─── عضویت‌ها و زمینه ────────────────────────────────────────────────────────
 * یک حساب می‌تواند در چند مجتمع عضوِ فعال باشد (یکی برای هر مجتمع). `users.complex_id`
 * و `users.role` **پیش‌فرضِ زمینه** است: نشستِ تازه با همان شروع می‌شود. از ۱۲/۱ هر نشست
 * مجتمعِ خودش را دارد (`SessionContext`) و نقشِ هر درخواست از عضویتِ فعالِ همان مجتمع
 * می‌آید؛ `SetCurrentComplex` روی هر درخواست می‌سنجد که پیش‌فرض هم پشتش عضویتِ فعالِ
 * هم‌خوان داشته باشد — وگرنه رد، نه بازگشت به جای دیگر.
 *
 * ─── یک نویسنده ─────────────────────────────────────────────────────────────
 * عضویت و زمینه فقط از همین کلاس و در یک تراکنش عوض می‌شوند؛ `User` تغییرِ
 * `complex_id`/`role` را بیرون از این‌جا رد می‌کند. `violations()` هر ناهم‌خوانیِ
 * باقی‌مانده را گزارش می‌کند (`memberships:check`).
 *
 * ─── نگهبانِ ورود ───────────────────────────────────────────────────────────
 * دفتر چند عضویت را می‌پذیرد؛ مسیرهای پیوستن (دعوت، درخواست، خرید) هنوز فقط حسابِ
 * اولیه را راه می‌دهند — تا رابطِ تعویضِ مجتمع (۱۰/۴) نباشد، عضوی که به مجتمعِ دوم
 * بپیوندد راهی در رابط برای برگشتن ندارد.
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
     * برقراریِ عضویت — و رفتن به زمینه‌ی آن وقتی `$activate`.
     *
     * عضویتِ فعالِ دوم در **همان** مجتمع پذیرفته نیست (و `active_key` هم رد می‌کند).
     *
     * ⚠️ `$activate = false` (مرحلهٔ ۱۰ · فاز ۵): وقتی عضویت را **کسِ دیگری** می‌سازد — مدیری که
     * درخواست را تأیید می‌کند، یا بازگشتِ درگاه پس از خرید — زمینه‌ی فعالِ حسابی که همین حالا در
     * مجتمعِ دیگری کار می‌کند جابه‌جا نمی‌شود: تب‌های بازش داده‌ی مجتمعِ تازه را زیرِ کلیدِ مجتمعِ
     * قبلی می‌گرفتند. تعویض با خودِ کاربر است (`POST account/context`). حسابِ بی‌زمینه (اولیه) همیشه
     * به زمینه‌ی تازه می‌رود — جایی برای ماندن ندارد.
     */
    public function start(User $user, Complex $complex, UserRole $role, string $source, ?ComplexInvitation $via = null, bool $activate = true): ComplexMembership
    {
        if (! in_array($role, ComplexMembership::ROLES, true)) {
            throw DomainException::invalid('این نقش عضویتِ مجتمع نیست.', 'membership.invalid_role');
        }

        return DB::transaction(function () use ($user, $complex, $role, $source, $via, $activate): ComplexMembership {
            $account = $this->lock($user);

            if ($account->isSuperAdmin()) {
                throw DomainException::invalid('ادمینِ کل عضوِ مجتمع نمی‌شود.', 'membership.platform_account');
            }

            // عضویتِ معلق هم عضویت است: دعوت، درخواست یا خرید دورش نمی‌زند (۱۰/۶)
            if ($this->liveOf($account)->where('complex_id', $complex->id)->exists()) {
                throw DomainException::invalid('شما از قبل عضوِ همین مجتمع هستید.', 'account.already_member');
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

            if ($activate || $account->complex_id === null) {
                $this->writeAccount($account, ['complex_id' => $complex->id, 'role' => $role]);
            }
            $this->sync($user, $account);

            return $membership;
        }, attempts: 3);
    }

    /**
     * پایانِ عضویت در یک مجتمع — نه حذفِ حساب (بندِ ۷۴). ردیف با زمان، پایان‌دهنده و
     * دلیل می‌ماند. اگر همان مجتمع زمینه‌ی فعال بود، حساب به عضویتِ فعالِ دیگرش می‌رود
     * (تازه‌ترین)، وگرنه به حالتِ اولیه. عضویت‌های دیگر دست نمی‌خورند (بندِ ۱۲).
     */
    public function end(User $user, string $reason, ?User $by = null, ?int $complexId = null): void
    {
        DB::transaction(function () use ($user, $reason, $by, $complexId): void {
            $account = $this->lock($user);
            $target = $complexId ?? $account->complex_id;

            if ($target === null) {
                throw DomainException::invalid('این کاربر عضوِ مجتمعی نیست.', 'membership.not_member');
            }

            $this->close($account, $reason, $by, $target);

            if ($account->complex_id === $target) {
                $this->fallBack($account);
            }

            $this->sync($user, $account);
        }, attempts: 3);
    }

    /**
     * تغییرِ نقش در یک مجتمع (پیش‌فرض: زمینه‌ی فعال). ارتقا به ادمینِ کل **همه‌ی**
     * عضویت‌ها را با دلیلِ «promoted» می‌بندد — نقشِ سکو در هیچ مجتمعی عضو نیست.
     */
    public function changeRole(User $user, UserRole $role, ?User $by = null, ?int $complexId = null): void
    {
        DB::transaction(function () use ($user, $role, $by, $complexId): void {
            $account = $this->lock($user);
            $target = $complexId ?? $account->complex_id;

            if ($role === UserRole::SuperAdmin) {
                $this->close($account, ComplexMembership::END_PROMOTED, $by);
                $this->writeAccount($account, ['complex_id' => null, 'role' => $role]);
            } elseif ($target !== null) {
                $updated = $this->liveOf($account)->where('complex_id', $target)
                    ->update(['role' => $role->value, 'updated_at' => now()]);

                if ($updated === 0) {
                    throw DomainException::invalid('این کاربر عضوِ فعالِ این مجتمع نیست.', 'membership.not_member');
                }
                if ($account->complex_id === $target) {
                    $this->writeAccount($account, ['role' => $role]);
                }
            } else {
                // حسابِ بی‌عضویت (اولیه یا ادمینِ کلِ پیشین): عضویتی نیست که عوض شود
                $this->writeAccount($account, ['role' => $role]);
            }

            $this->sync($user, $account);
        }, attempts: 3);
    }

    /**
     * رفتن به زمینه‌ی مجتمعِ دیگر (بندهای ۱۴ و ۹۲) — فقط به مجتمعی که عضویتِ فعال دارد،
     * و نقش همان نقشِ عضویتِ آن‌جاست: مدیرِ الف که به ب (مالک) می‌رود دیگر مدیر نیست.
     */
    public function switchTo(User $user, int $complexId): ComplexMembership
    {
        return DB::transaction(function () use ($user, $complexId): ComplexMembership {
            $account = $this->lock($user);

            $membership = $this->activeOf($account)->where('complex_id', $complexId)->first();

            if ($membership === null) {
                // همان پاسخ برای «مجتمعِ ناموجود» و «مجتمعِ دیگران»: وجودِ مجتمع لو نمی‌رود
                throw DomainException::invalid('عضویتِ فعالی در این مجتمع ندارید.', 'membership.not_member');
            }

            $this->writeAccount($account, ['complex_id' => $membership->complex_id, 'role' => $membership->role]);
            $this->sync($user, $account);

            return $membership;
        }, attempts: 3);
    }

    /**
     * تعلیقِ عضویت در یک مجتمع به دستِ مدیرِ همان‌جا (مرحلهٔ ۱۰ · فاز ۶).
     *
     * دسترسی به همین مجتمع بسته می‌شود — زنجیره‌ی مجوز فقط عضویتِ **فعال** را می‌پذیرد — و هیچ چیزِ دیگری
     * عوض نمی‌شود: حساب، واحد و دوره، قبض و کیف، پیام‌ها و عضویت‌های دیگر. اگر همین مجتمع زمینه‌ی فعالِ حساب
     * بود، حساب مثلِ پایانِ عضویت به عضویتِ فعالِ دیگرش (یا حالتِ اولیه) می‌رود.
     */
    public function suspend(User $user, int $complexId, ?User $by = null): ComplexMembership
    {
        return DB::transaction(function () use ($user, $complexId, $by): ComplexMembership {
            $account = $this->lock($user);
            $membership = $this->activeOf($account)->where('complex_id', $complexId)->lockForUpdate()->first();

            if ($membership === null) {
                throw DomainException::invalid('عضویتِ فعالی در این مجتمع نیست که معلق شود.', 'membership.not_active');
            }

            $membership->forceFill([
                'status' => ComplexMembership::SUSPENDED,
                'suspended_at' => now(),
                'suspended_by' => $by?->id,
            ])->save();

            if ($account->complex_id === $complexId) {
                $this->fallBack($account);
            }
            $this->sync($user, $account);

            return $membership;
        }, attempts: 3);
    }

    /**
     * برداشتنِ تعلیق. حسابِ بی‌زمینه (همین تنها عضویتش بود) به همین مجتمع برمی‌گردد؛ حسابی که جای دیگری کار
     * می‌کند همان‌جا می‌ماند و خودش تعویض می‌کند — همان قاعده‌ی تأییدِ درخواست (۱۰/۵).
     */
    public function resume(User $user, int $complexId): ComplexMembership
    {
        return DB::transaction(function () use ($user, $complexId): ComplexMembership {
            $account = $this->lock($user);
            $membership = $this->liveOf($account)->where('complex_id', $complexId)
                ->where('status', ComplexMembership::SUSPENDED)->lockForUpdate()->first();

            if ($membership === null) {
                throw DomainException::invalid('عضویتِ معلقی در این مجتمع نیست.', 'membership.not_suspended');
            }

            $membership->forceFill(['status' => ComplexMembership::ACTIVE, 'suspended_at' => null, 'suspended_by' => null])->save();

            if ($account->complex_id === null) {
                $this->writeAccount($account, ['complex_id' => $membership->complex_id, 'role' => $membership->role]);
            }
            $this->sync($user, $account);

            return $membership;
        }, attempts: 3);
    }

    /**
     * حسابی که از ابتدا درونِ مجتمع ساخته شد (دادهٔ نمونه، ابزارِ آزمون) عضوِ همان
     * مجتمع است. ثبت‌نام و ادمینِ کل بی‌مجتمع ساخته می‌شوند و این‌جا کاری ندارند.
     */
    public function recordCreated(User $user): void
    {
        // نقشِ نیامده یعنی پیش‌فرضِ ستون (`tenant`)؛ همان که در پایگاه‌داده نشست خوانده شود
        if ($user->complex_id !== null && $user->getAttribute('role') === null) {
            $user->refresh();
        }

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
     * هر ناهم‌خوانیِ میانِ زمینه‌ی فعال و عضویت؛ خالی یعنی سالم.
     *
     * @return list<string>
     */
    public function violations(): array
    {
        $problems = [];

        $accounts = User::withoutGlobalScopes()
            ->whereNotNull('complex_id')
            ->orWhereIn('id', ComplexMembership::withoutGlobalScopes()->active()->select('user_id'))
            ->get(['id', 'complex_id', 'role']);

        $active = ComplexMembership::withoutGlobalScopes()->active()->get(['id', 'user_id', 'complex_id', 'role'])->groupBy('user_id');

        foreach ($accounts as $account) {
            $mine = $active->get($account->id, collect());

            if ($account->isSuperAdmin()) {
                if ($mine->isNotEmpty()) {
                    $problems[] = "ادمینِ کل {$account->id} عضویتِ فعال دارد.";
                }
                if ($account->complex_id !== null) {
                    $problems[] = "ادمینِ کل {$account->id} زمینه‌ی مجتمعی روی حساب دارد.";
                }

                continue;
            }

            if ($account->complex_id === null) {
                if ($mine->isNotEmpty()) {
                    $problems[] = "حسابِ {$account->id} عضویتِ فعال دارد ولی زمینه‌ی فعالش خالی است.";
                }

                continue;
            }

            $match = $mine->first(fn (ComplexMembership $m) => $m->complex_id === $account->complex_id && $m->role === $account->role);
            if ($match === null) {
                $problems[] = "حسابِ {$account->id} در زمینه‌ی مجتمعِ {$account->complex_id} با نقشِ {$account->role->value} است ولی عضویتِ فعالِ هم‌خوان ندارد.";
            }
        }

        return $problems;
    }

    private function lock(User $user): User
    {
        return User::withoutGlobalScopes()->lockForUpdate()->findOrFail($user->id);
    }

    /** @return Builder<ComplexMembership> */
    private function activeOf(User $account)
    {
        return ComplexMembership::withoutGlobalScopes()->active()->where('user_id', $account->id);
    }

    /** @return Builder<ComplexMembership> */
    private function liveOf(User $account)
    {
        return ComplexMembership::withoutGlobalScopes()->live()->where('user_id', $account->id);
    }

    /** زمینه پس از پایانِ عضویتِ زمینه: تازه‌ترین عضویتِ فعالِ دیگر، وگرنه حالتِ اولیه. */
    private function fallBack(User $account): void
    {
        $next = $this->activeOf($account)->orderByDesc('started_at')->orderByDesc('id')->first();

        $this->writeAccount($account, $next === null
            // نقشِ حسابِ اولیه همان است که ثبت‌نام می‌دهد (`AuthController::registerVerify`)
            ? ['complex_id' => null, 'role' => UserRole::Owner]
            : ['complex_id' => $next->complex_id, 'role' => $next->role]);
    }

    private function close(User $account, string $reason, ?User $by, ?int $complexId = null): void
    {
        // پایانِ عضویتِ معلق هم پایان است؛ زمانِ تعلیقش به‌عنوانِ سابقه روی ردیف می‌ماند
        $this->liveOf($account)
            ->when($complexId !== null, fn ($q) => $q->where('complex_id', $complexId))
            ->update([
                'status' => ComplexMembership::ENDED,
                'ended_at' => now(),
                'ended_by' => $by?->id,
                'end_reason' => $reason,
                'active_key' => null,
                'updated_at' => now(),
            ]);
    }

    private function sync(User $caller, User $account): void
    {
        $caller->setRawAttributes($account->getAttributes(), true);
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

        // پیش‌فرضِ حساب عوض شد؛ اگر کارِ خودِ همین حساب بود، نشستِ همین درخواست هم (۱۲/۱، `SessionContext`)
        if (array_key_exists('complex_id', $changes)) {
            SessionContext::follow($account);
        }
    }
}
