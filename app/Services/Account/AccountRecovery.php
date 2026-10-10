<?php

namespace App\Services\Account;

use App\Exceptions\DomainException;
use App\Models\AccountRecoveryRequest;
use App\Models\User;
use App\Notifications\PhoneChangedNotification;
use App\Services\Auth\OtpService;
use App\Services\Auth\SessionRegistry;
use App\Support\Audit;
use App\Support\Phone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * بازیابیِ حساب وقتی شماره‌ی قبلی در دسترس نیست (مرحلهٔ ۹ · تصمیمِ کارفرما).
 *
 * «تغییرِ عادیِ شماره با بازیابی یکسان نیست»: این‌جا کاربر واردشده نیست و
 * **اثباتِ شماره‌ی تازه به‌تنهایی حساب را نمی‌دهد**. درخواست فقط ثبت می‌شود؛
 * شماره‌ی canonical تنها با تأییدِ ادمینِ کل، پس از بررسیِ شواهد، و زیرِ قفل عوض
 * می‌شود — همه‌ی نشست‌ها و دستگاه‌های مورداعتماد آن حساب هم باطل می‌شوند.
 *
 * ─── آنچه عمداً **نیست** ──────────────────────────────────────────────────
 * • پاسخِ ثبت نمی‌گوید شماره‌ی قبلی حساب دارد یا نه (شمارشِ حساب).
 * • ادغامِ حساب نیست: شماره‌ی تازه‌ای که حساب دارد هرگز کد نمی‌گیرد و تأیید نمی‌شود.
 * • ادمینِ کل بازیابیِ حسابِ خودش را تأیید نمی‌کند.
 * • کیف، دفتر، عضویت‌ها و تاریخچه به `user_id` بسته‌اند و دست نمی‌خورند.
 *
 * ⚠️ «بررسیِ شواهد» (تماس، مدرکِ هویت، تأییدِ مدیرِ مجتمع) فرایندِ عملیاتیِ بیرون
 * از سامانه است؛ سامانه فقط آن را ثبت‌پذیر و قابلِ ردیابی می‌کند.
 */
class AccountRecovery
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly SessionRegistry $sessions,
    ) {}

    /** @return array{devCode: string|null} */
    public function sendCode(string $newPhone): array
    {
        $newPhone = Phone::normalize($newPhone);
        $result = $this->otp->issue($newPhone, deliver: ! $this->taken($newPhone));

        if (! $result['ok']) {
            throw ValidationException::withMessages(['new_phone' => (string) $result['error']]);
        }

        return ['devCode' => $result['dev_code']];
    }

    public function submit(string $oldPhone, string $newPhone, string $code, ?string $nationalId, string $explanation, ?string $ip): AccountRecoveryRequest
    {
        $oldPhone = Phone::normalize($oldPhone);
        $newPhone = Phone::normalize($newPhone);

        if ($oldPhone === $newPhone) {
            throw ValidationException::withMessages(['new_phone' => 'شماره‌ی تازه باید با شماره‌ی قبلی فرق کند.']);
        }

        // بیرون از هر تراکنش، تا شمارِ تلاش‌های نادرست برنگردد
        if (! $this->otp->verify($newPhone, $code)) {
            throw ValidationException::withMessages(['code' => 'کد نادرست یا منقضی است.']);
        }

        $account = User::withoutGlobalScopes()->where('phone', $oldPhone)->first();

        $request = AccountRecoveryRequest::create([
            'user_id' => $account?->id,
            'old_phone' => $oldPhone,
            'new_phone' => $newPhone,
            'national_id' => $nationalId,
            'explanation' => $explanation,
            'status' => AccountRecoveryRequest::PENDING,
            'new_phone_verified_at' => now(),
            'ip_address' => $ip,
        ]);

        Audit::log('account_recovery.requested', 'درخواستِ بازیابیِ حساب با شماره‌ی تازه', $request, [
            'old_phone' => $oldPhone,
            'new_phone' => $newPhone,
            'matched_account' => $account?->id,
        ], $account?->complex_id);

        return $request;
    }

    public function approve(AccountRecoveryRequest $request, User $admin, ?string $note): User
    {
        [$account, $old] = DB::transaction(function () use ($request, $admin, $note): array {
            $fresh = AccountRecoveryRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! $fresh->isDecidable()) {
                throw DomainException::invalid('این درخواست دیگر قابلِ تأیید نیست (تصمیم گرفته شده یا منقضی است).', 'account_recovery.not_pending');
            }

            if ($fresh->user_id === null) {
                throw DomainException::invalid('هیچ حسابی با شماره‌ی قبلیِ این درخواست نیست.', 'account_recovery.no_account');
            }

            if ($fresh->user_id === $admin->id) {
                throw DomainException::invalid('بازیابیِ حسابِ خودتان را ادمینِ کلِ دیگری باید تأیید کند.', 'account_recovery.self_approval');
            }

            $account = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($fresh->user_id);

            if ($account->phone !== $fresh->old_phone) {
                throw DomainException::invalid('شماره‌ی حساب از زمانِ درخواست عوض شده است.', 'account_recovery.stale');
            }

            if ($this->taken($fresh->new_phone, $account)) {
                throw $this->unavailable();
            }

            try {
                // `remember_token` ِ تازه: کوکیِ «مرا به خاطر بسپار» ِ هر دستگاهی بی‌اثر می‌شود
                $account->forceFill(['phone' => $fresh->new_phone, 'remember_token' => Str::random(60)])->save();
            } catch (UniqueConstraintViolationException) {
                throw $this->unavailable();
            }

            $fresh->forceFill([
                'status' => AccountRecoveryRequest::APPROVED,
                'decided_by' => $admin->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            return [$account, $fresh->old_phone];
        }, attempts: 3);

        // همه‌ی نشست‌ها و دستگاه‌های مورداعتماد: صاحبِ واقعی از نو وارد می‌شود
        $this->sessions->endOthers($account, '');
        $account->trustedDevices()->delete();
        $this->otp->clear($account->phone);

        Audit::log('account_recovery.approved', 'تأییدِ بازیابیِ حساب و تغییرِ شماره', $account, [
            'request_id' => $request->id,
            'old_phone' => $old,
            'new_phone' => $account->phone,
        ], $account->complex_id);

        $account->notify(new PhoneChangedNotification((string) $account->phone));

        return $account;
    }

    public function reject(AccountRecoveryRequest $request, User $admin, string $note): void
    {
        DB::transaction(function () use ($request, $admin, $note): void {
            $fresh = AccountRecoveryRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== AccountRecoveryRequest::PENDING) {
                throw DomainException::invalid('درباره‌ی این درخواست پیش‌تر تصمیم گرفته شده است.', 'account_recovery.not_pending');
            }

            $fresh->forceFill([
                'status' => AccountRecoveryRequest::REJECTED,
                'decided_by' => $admin->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();
        }, attempts: 3);

        Audit::log('account_recovery.rejected', 'ردِ درخواستِ بازیابیِ حساب', $request, [
            'reason' => $note,
        ], $request->user?->complex_id);
    }

    private function taken(string $phone, ?User $except = null): bool
    {
        return User::withoutGlobalScopes()->where('phone', $phone)
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->exists();
    }

    private function unavailable(): DomainException
    {
        return DomainException::invalid('شماره‌ی تازه اکنون متعلق به حسابِ دیگری است.', 'account_recovery.unavailable');
    }
}
