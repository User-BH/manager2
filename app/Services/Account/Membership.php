<?php

namespace App\Services\Account;

use App\Enums\AccountState;
use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Exceptions\DomainException;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\Unit;
use App\Models\User;
use App\Services\Units\TenureService;
use App\Support\Phone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * مسیرِ عضویت در مجتمعِ موجود — مرحلهٔ ۹ · فاز ۶.
 *
 * تنها جایی که حساب به مجتمع وصل یا از آن جدا می‌شود (جز `ComplexPurchase` که
 * مدیرِ مجتمعِ تازه را می‌سازد). فایلِ معماری (۱۰/۲) جدولِ عضویت را فقط همین‌جا
 * عوض می‌کند.
 *
 * ─── سه قاعده ──────────────────────────────────────────────────────────────
 * • **حساب ساخته نمی‌شود (F5).** مدیر به شماره دعوت می‌فرستد؛ صاحبِ شماره
 *   خودش با OTP ثبت‌نام می‌کند و نام و رمزش مالِ خودش است.
 * • **ساکن بی‌واحد نیست (F6).** مالک و مستأجر فقط با واحدی از همان مجتمع
 *   می‌پیوندند؛ دوره از `TenureService` باز می‌شود، نه بازنویسیِ جدولِ واسط.
 * • **پایانِ عضویت حساب را پاک نمی‌کند (F4).** دوره‌ها بسته می‌شوند و حساب به
 *   حالتِ اولیه برمی‌گردد؛ پرداخت، قبض، پیام و سابقه‌ی واحد همه می‌مانند.
 */
class Membership
{
    /** نقش‌هایی که فقط با واحد معنا دارند */
    private const UNIT_ROLES = [UserRole::Owner, UserRole::Tenant];

    public function __construct(private readonly TenureService $tenures) {}

    /**
     * دعوتِ امن به یک شماره. توکنِ خام فقط در خروجیِ همین متد است.
     *
     * دعوتِ زنده‌ی قبلیِ همان (مجتمع، شماره) باطل می‌شود: «دوباره بفرست»
     * یعنی توکنِ تازه، و توکنِ قبلی دیگر کار نمی‌کند.
     *
     * @return array{0: ComplexInvitation, 1: string}
     */
    public function invite(Complex $complex, string $phone, UserRole $role, ?Unit $unit, ?User $inviter): array
    {
        $phone = Phone::normalize($phone);

        $this->guardPlacement($complex, $role, $unit);

        $account = User::withoutGlobalScopes()->where('phone', $phone)->first();

        if ($account?->role === UserRole::SuperAdmin) {
            throw $this->phoneError('این شماره متعلق به ادمینِ سامانه است.', 'invitation.platform_account');
        }

        if ($account && $account->complex_id === $complex->id) {
            throw $this->phoneError('این شماره از قبل عضوِ همین مجتمع است.', 'invitation.already_member');
        }

        if ($account && $account->complex_id !== null) {
            // همان پاسخِ پیشین (`phone.unique`)؛ مجتمعِ دیگر نام برده نمی‌شود
            throw $this->phoneError('این شماره تلفن قبلا ثبت شده است.', 'invitation.member_elsewhere');
        }

        if ($unit && $role === UserRole::Owner) {
            // زودتر از پذیرش هم گفته می‌شود؛ قاعده‌ی قطعی همان `open` در پذیرش است
            $this->tenures->ensureOwnerShare($unit, 100);
        }

        try {
            return DB::transaction(function () use ($complex, $phone, $role, $unit, $inviter): array {
                // `live_key` یکتاست: حداکثر یک دعوتِ زنده، که زیرِ قفل باطل می‌شود
                $old = ComplexInvitation::where('live_key', ComplexInvitation::liveKey($complex->id, $phone))->lockForUpdate()->first();
                $old?->close(ComplexInvitation::REVOKED);

                return ComplexInvitation::issue([
                    'complex_id' => $complex->id,
                    'phone' => $phone,
                    'unit_id' => $unit?->id,
                    'role' => $role,
                    'invited_by' => $inviter?->id,
                ]);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            /*
             * دو درخواستِ هم‌زمان برای همان شماره (دوبار-کلیک): هر دو «دعوتی نیست»
             * دیدند و دومی به یکتاییِ `live_key` خورد. نتیجه همان یک دعوت است.
             */
            throw DomainException::conflict('برای این شماره همین حالا دعوتی ساخته شد.', 'invitation.concurrent');
        }
    }

    /** ابطال؛ فقط دعوتِ زنده. */
    public function revoke(ComplexInvitation $invitation): void
    {
        DB::transaction(function () use ($invitation): void {
            $fresh = ComplexInvitation::lockForUpdate()->find($invitation->id);

            if (! $fresh || $fresh->direction !== ComplexInvitation::INVITE || ! $fresh->isLive()) {
                throw DomainException::invalid('این دعوت دیگر در انتظار نیست.', 'invitation.not_pending');
            }

            $fresh->close(ComplexInvitation::REVOKED);
        }, attempts: 3);
    }

    /**
     * پذیرشِ دعوت. کدِ OTP پیش از این در کنترلر سنجیده شده است.
     *
     * همه‌ی شرط‌ها **زیرِ قفل** دوباره خوانده می‌شوند: دو پذیرشِ هم‌زمان، یا
     * پذیرش هم‌زمان با ابطال، فقط یک نتیجه دارند.
     */
    public function accept(ComplexInvitation $invitation, User $user): void
    {
        DB::transaction(function () use ($invitation, $user): void {
            $fresh = ComplexInvitation::lockForUpdate()->find($invitation->id);

            if (! $fresh || $fresh->direction !== ComplexInvitation::INVITE || ! $fresh->isLive()) {
                throw DomainException::invalid('این دعوت دیگر معتبر نیست.', 'invitation.not_pending');
            }

            $account = User::withoutGlobalScopes()->lockForUpdate()->find($user->id);

            // دعوت به شماره بسته است؛ کنترلر هم فقط دعوتِ همین شماره را نشان می‌دهد
            if (! $account || $fresh->phone === null || $account->phone !== $fresh->phone) {
                throw DomainException::invalid('این دعوت برای شماره‌ی شما نیست.', 'invitation.wrong_phone');
            }

            if (AccountState::of($account) !== AccountState::Initial) {
                throw DomainException::invalid('شما از قبل عضو یک مجتمع هستید.', 'account.already_member');
            }

            $unit = $fresh->unit_id ? Unit::withoutGlobalScopes()->find($fresh->unit_id) : null;
            $complex = Complex::findOrFail($fresh->complex_id);

            $this->join($account, $complex, $fresh->role, $unit);

            $fresh->close(ComplexInvitation::ACCEPTED, $account->id);
            $this->closeOthers($account, $fresh->id);
        }, attempts: 3);
    }

    public function decline(ComplexInvitation $invitation, User $user): void
    {
        DB::transaction(function () use ($invitation, $user): void {
            $fresh = ComplexInvitation::lockForUpdate()->find($invitation->id);

            if (! $fresh || ! $fresh->isLive()) {
                throw DomainException::invalid('این دعوت دیگر معتبر نیست.', 'invitation.not_pending');
            }

            $fresh->close(ComplexInvitation::DECLINED, $user->id);
        }, attempts: 3);
    }

    /**
     * تأییدِ درخواستِ پیوستن — F6: با نقش **و واحد**.
     */
    public function approveRequest(ComplexInvitation $request, UserRole $role, Unit $unit): void
    {
        DB::transaction(function () use ($request, $role, $unit): void {
            $fresh = ComplexInvitation::lockForUpdate()->find($request->id);

            if (! $fresh || $fresh->direction !== ComplexInvitation::REQUEST || $fresh->status !== ComplexInvitation::PENDING) {
                throw DomainException::invalid('این درخواست دیگر معتبر نیست.', 'invitation.not_pending');
            }

            $applicant = User::withoutGlobalScopes()->lockForUpdate()->find($fresh->user_id);

            /*
             * ممکن است بینِ ارسال و تایید، خودش جای دیگری عضو شده باشد.
             * تاییدِ کورکورانه او را بی‌خبر از مجتمعِ فعلی‌اش بیرون می‌کشید.
             */
            if (! $applicant || AccountState::of($applicant) !== AccountState::Initial) {
                throw DomainException::invalid('این کاربر دیگر در انتظار پیوستن نیست.', 'join_request.user_unavailable');
            }

            $complex = Complex::findOrFail($fresh->complex_id);
            $this->join($applicant, $complex, $role, $unit);

            $fresh->forceFill(['role' => $role, 'unit_id' => $unit->id])->save();
            $fresh->close(ComplexInvitation::ACCEPTED);
            $this->closeOthers($applicant, $fresh->id);
        }, attempts: 3);
    }

    /**
     * پایانِ عضویت (F4) — به‌جای حذفِ حساب.
     *
     * دوره‌های جاری بسته می‌شوند (`end_date` امروز)، حساب به حالتِ اولیه
     * برمی‌گردد و چیزی حذف نمی‌شود. ساکنِ رفته دیگر داده‌ی مجتمع را نمی‌بیند
     * (دامنه‌ی مستأجر از `complex_id` است) و ساکنِ بعدی دوره‌ی خودش را دارد.
     */
    public function end(User $member): void
    {
        DB::transaction(function () use ($member): void {
            $account = User::withoutGlobalScopes()->lockForUpdate()->find($member->id);

            if (! $account || $account->complex_id === null) {
                throw DomainException::invalid('این کاربر عضوِ مجتمعی نیست.', 'membership.not_member');
            }

            $this->tenures->endAllFor($account);

            $account->forceFill([
                'complex_id' => null,
                // نقشِ حسابِ اولیه همان است که ثبت‌نام می‌دهد (`AuthController::registerVerify`)
                'role' => UserRole::Owner,
            ])->save();
        }, attempts: 3);
    }

    /** نقش و واحد با هم جور باشند و واحد مالِ همین مجتمع (F6). */
    private function guardPlacement(Complex $complex, UserRole $role, ?Unit $unit): void
    {
        if (! in_array($role, [...self::UNIT_ROLES, UserRole::ComplexAdmin], true)) {
            throw DomainException::invalid('این نقش با دعوت داده نمی‌شود.', 'invitation.invalid_role');
        }

        if (in_array($role, self::UNIT_ROLES, true) && $unit === null) {
            throw DomainException::invalid('برای مالک و مستأجر انتخابِ واحد لازم است.', 'membership.unit_required');
        }

        if ($role === UserRole::ComplexAdmin && $unit !== null) {
            throw DomainException::invalid('مدیر به واحد بسته نمی‌شود.', 'membership.unit_not_allowed');
        }

        if ($unit && ($unit->complex_id !== $complex->id || $unit->trashed())) {
            throw DomainException::invalid('این واحد در این مجتمع نیست.', 'membership.unit_mismatch');
        }
    }

    private function phoneError(string $message, string $code): DomainException
    {
        return DomainException::invalid($message, $code, ['phone' => [$message]]);
    }

    private function join(User $user, Complex $complex, UserRole $role, ?Unit $unit): void
    {
        $this->guardPlacement($complex, $role, $unit);

        $user->forceFill(['complex_id' => $complex->id, 'role' => $role])->save();

        if ($unit !== null) {
            $this->tenures->open(
                $unit,
                $user,
                $role === UserRole::Owner ? ResidentRelation::Owner : ResidentRelation::Tenant,
            );
        }
    }

    /**
     * کاربر حالا عضو است: دعوت‌های زنده‌ی دیگرِ همین شماره و درخواست‌هایش
     * باطل می‌شوند، وگرنه پذیرفتنِ دومی یعنی جابه‌جا شدنِ بی‌سروصدا.
     */
    private function closeOthers(User $user, int $except): void
    {
        ComplexInvitation::query()
            ->whereKeyNot($except)
            ->where('status', ComplexInvitation::PENDING)
            ->where(fn ($q) => $q->where('phone', $user->phone)->orWhere('user_id', $user->id))
            ->lockForUpdate()
            ->get()
            ->each(fn (ComplexInvitation $other) => $other->close(ComplexInvitation::DECLINED));
    }
}
