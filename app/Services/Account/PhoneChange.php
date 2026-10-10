<?php

namespace App\Services\Account;

use App\Exceptions\DomainException;
use App\Models\PhoneChangeRequest;
use App\Models\User;
use App\Notifications\PhoneChangedNotification;
use App\Services\Auth\OtpService;
use App\Support\Audit;
use App\Support\Phone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * تغییرِ شماره‌ی حساب **توسطِ خودِ صاحبِ حساب** (مرحلهٔ ۹ · تصمیمِ کارفرما).
 *
 * دو گام: (۱) شماره‌ی تازه، پشتِ تأییدِ دوباره‌ی رمز (`reauth`)؛ کدِ پیامکی فقط به
 * شماره‌ای می‌رود که حسابِ دیگری ندارد. (۲) کد؛ زیرِ قفلِ حساب: کد، آزاد بودنِ
 * شماره و نوشتن — و ایندکسِ یکتای `users.phone` پشتوانه‌ی دیتابیسیِ آن.
 *
 * ─── آنچه عمداً **نیست** ──────────────────────────────────────────────────
 * • مدیر یا هیچ کسِ دیگری از این مسیر شماره‌ی دیگری را عوض نمی‌کند: صاحب همیشه
 *   کاربرِ واردشده است و هیچ شناسه‌ای از درخواست خوانده نمی‌شود.
 * • ادغامِ حساب نیست: شماره‌ای که حساب دارد هرگز به این حساب نمی‌رسد.
 * • پاسخِ گامِ اول نمی‌گوید شماره حساب دارد یا نه (همان قاعده‌ی شمارشِ حساب،
 *   `3cd142d`): ردیفِ کد برای هر شماره ساخته می‌شود و فقط پیامک فرق می‌کند.
 * • کیف، دفتر، عضویت‌ها، دوره‌ها و نویسنده‌ی پیام‌ها به `user_id` بسته‌اند، نه
 *   شماره؛ این‌جا دست نمی‌خورند.
 *
 * شماره‌ی از دست رفته (بی دسترسی به حساب) مسیرِ جدای `AccountRecovery` است.
 */
class PhoneChange
{
    public function __construct(private readonly OtpService $otp) {}

    /**
     * گامِ اول: ثبتِ شماره‌ی تازه و فرستادنِ کد — فقط اگر شماره آزاد باشد.
     *
     * @return array{devCode: string|null}
     */
    public function start(User $user, string $phone): array
    {
        $phone = Phone::normalize($phone);

        if ($phone === $user->phone) {
            throw ValidationException::withMessages(['phone' => 'این همان شماره‌ی فعلیِ حسابِ شماست.']);
        }

        $taken = $this->taken($phone, $user);

        PhoneChangeRequest::updateOrCreate(
            ['user_id' => $user->id],
            ['new_phone' => $phone, 'expires_at' => now()->addMinutes(PhoneChangeRequest::TTL_MINUTES)],
        );

        $result = $this->otp->issue($phone, deliver: ! $taken);

        if (! $result['ok']) {
            throw ValidationException::withMessages(['phone' => (string) $result['error']]);
        }

        Audit::log('account.phone_change_requested', 'درخواستِ تغییرِ شماره‌ی حساب', $user, [
            'new_phone_tail' => substr($phone, -4),
        ], $user->complex_id);

        return ['devCode' => $result['dev_code']];
    }

    /**
     * گامِ دوم: تأییدِ کد و نوشتنِ شماره‌ی canonical — اتمیک.
     *
     * @return string شماره‌ی قبلی
     */
    public function confirm(User $user, string $code): string
    {
        $pending = $this->pending($user);

        /*
         * ⚠️ کد **بیرون** از تراکنش سنجیده می‌شود: `verify` شمارِ تلاش را بالا می‌برد
         * و استثنای درونِ تراکنش آن را برمی‌گرداند — یعنی سقفِ ۵ تلاش بی‌اثر می‌شد.
         * کدِ سنجیده‌شده مصرف شده است؛ تکرارِ هم‌زمانِ همین درخواست زیرِ قفل درخواستِ
         * زنده‌ای پیدا نمی‌کند.
         */
        if (! $this->otp->verify($pending->new_phone, $code)) {
            throw ValidationException::withMessages(['code' => 'کد نادرست یا منقضی است.']);
        }

        [$old, $new] = DB::transaction(function () use ($user, $pending): array {
            $account = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($user->id);
            $live = PhoneChangeRequest::whereKey($pending->id)->lockForUpdate()->first();

            if ($live === null || $live->new_phone !== $pending->new_phone || $live->expires_at->isPast()) {
                throw $this->expired();
            }

            // دوباره زیرِ قفل: شاید در این فاصله حسابِ دیگری همین شماره را گرفته باشد
            if ($this->taken($live->new_phone, $account)) {
                throw $this->unavailable();
            }

            $old = (string) $account->phone;

            try {
                // `remember_token` ِ تازه: کوکیِ «مرا به خاطر بسپار» ِ دستگاه‌های دیگر بی‌اثر می‌شود
                $account->forceFill(['phone' => $live->new_phone, 'remember_token' => Str::random(60)])->save();
            } catch (UniqueConstraintViolationException) {
                throw $this->unavailable();
            }

            $live->delete();

            return [$old, (string) $account->phone];
        }, attempts: 3);

        $this->otp->clear($new);

        $fresh = User::withoutGlobalScopes()->findOrFail($user->id);
        $fresh->trustedDevices()->delete();

        Audit::log('account.phone_changed', 'تغییرِ شماره‌ی حساب توسطِ خودِ کاربر', $fresh, [
            'old_phone' => $old,
            'new_phone' => $new,
        ], $fresh->complex_id);

        $fresh->notify(new PhoneChangedNotification($new));

        return $old;
    }

    private function pending(User $user): PhoneChangeRequest
    {
        $pending = PhoneChangeRequest::where('user_id', $user->id)->first();

        if ($pending === null || $pending->expires_at->isPast()) {
            throw $this->expired();
        }

        return $pending;
    }

    private function expired(): DomainException
    {
        return DomainException::invalid('درخواستِ تغییرِ شماره منقضی شده است؛ دوباره شروع کنید.', 'phone_change.expired');
    }

    private function taken(string $phone, User $except): bool
    {
        return User::withoutGlobalScopes()->where('phone', $phone)->whereKeyNot($except->id)->exists();
    }

    private function unavailable(): DomainException
    {
        return DomainException::invalid('این شماره برای حسابِ شما قابلِ استفاده نیست.', 'phone_change.unavailable');
    }
}
