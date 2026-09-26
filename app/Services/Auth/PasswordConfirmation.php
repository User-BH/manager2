<?php

namespace App\Services\Auth;

use App\Exceptions\DomainException;
use Illuminate\Http\Request;

/**
 * «رمزت را دوباره بزن» پیش از عملیاتِ حساس (مرحلهٔ ۵ · فاز ۱۰).
 *
 * ─── چرا ─────────────────────────────────────────────────────────────────────
 * نشستِ واردشده کافی نیست وقتی کاری برگشت‌ناپذیر یا خطرناک است: بازگردانیِ
 * بکاپ، دانلودِ کلِ داده، عوض‌کردنِ شماره‌کارتی که ساکنین به آن پول می‌زنند،
 * دادنِ نقشِ مدیر، خاموش‌کردنِ کدِ پیامکی. کسی که کوکیِ نشست را دزدیده —
 * یا از گوشیِ بازمانده‌ی کسی استفاده می‌کند — رمز را نمی‌داند.
 *
 * تأیید در خودِ نشست ثبت می‌شود و `auth.password_timeout` ثانیه اعتبار دارد؛
 * پس کاربر برای چند کارِ پشتِ‌سرِهم یک بار رمز می‌زند.
 */
class PasswordConfirmation
{
    public const KEY = 'auth.password_confirmed_at';

    public function confirm(Request $request): void
    {
        $request->session()->put(self::KEY, now()->timestamp);
    }

    public function isRecent(Request $request): bool
    {
        $at = (int) $request->session()->get(self::KEY, 0);

        return $at > 0 && now()->timestamp - $at <= (int) config('auth.password_timeout');
    }

    /** @throws DomainException */
    public function ensure(Request $request): void
    {
        if (! $this->isRecent($request)) {
            throw DomainException::reauthRequired();
        }
    }
}
