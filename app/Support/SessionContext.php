<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * مجتمعِ انتخاب‌شده‌ی **همین نشست** (مرحلهٔ ۱۲ · فاز ۱؛ بندهای ۴ و ۱۸ ِ سندِ هسته).
 *
 * ─── چرا نشست، نه حساب ────────────────────────────────────────────────────
 * تا ۱۲/۱ زمینه فقط روی ردیفِ حساب بود (`users.complex_id`، `users.role`): تعویضِ مجتمع روی یک دستگاه زمینه‌ی
 * دستگاهِ دیگرِ همان حساب را هم عوض می‌کرد، و «نقش» شکلِ ویژگیِ دائمیِ حساب داشت. حالا:
 *
 *   حساب ← **مجتمعِ این نشست** ← عضویتِ فعال ← نقشِ همان عضویت ← مجوز ← داشبورد
 *
 * ستون‌های حساب «پیش‌فرض» اند: نشستِ تازه با همان شروع می‌شود و در اولین درخواست به آن سنجاق می‌شود؛ از آن پس
 * تغییرِ پیش‌فرض (تعویض روی دستگاهِ دیگر) این نشست را جابه‌جا نمی‌کند. نویسنده‌ی پیش‌فرض همچنان فقط
 * `MembershipLedger` است و وقتی کاری از **خودِ** همین حساب زمینه‌اش را عوض می‌کند (تعویض، پذیرشِ دعوت، خرید)،
 * نشستِ همان درخواست را هم دنبالش می‌برد (`follow`).
 *
 * اختیار از سنجاق نمی‌آید: `SetCurrentComplex` روی هر درخواست عضویتِ فعال را می‌سنجد و نقش را از **همان عضویت**
 * می‌گیرد. سنجاقِ مجتمعی که عضویتش پایان یافته یا معلق شده به پیش‌فرض برمی‌گردد. سنجاق به شناسه‌ی حساب بسته است
 * تا نشستی که دستِ حسابِ دیگری بیفتد چیزی از قبلی نبرد.
 */
final class SessionContext
{
    public const KEY = 'context';

    /** مجتمعِ سنجاق‌شده‌ی این نشست برای همین حساب، یا null */
    public static function pinned(User $user): ?int
    {
        $request = request();
        if (! $request->hasSession()) {
            return null;
        }

        $value = $request->session()->get(self::KEY);

        return is_array($value) && ($value['user'] ?? null) === $user->id && is_int($value['complex'] ?? null)
            ? $value['complex']
            : null;
    }

    public static function pin(User $user, ?int $complexId): void
    {
        $request = request();
        if (! $request->hasSession()) {
            return;
        }

        $complexId === null
            ? $request->session()->forget(self::KEY)
            : $request->session()->put(self::KEY, ['user' => $user->id, 'complex' => $complexId]);
    }

    /**
     * همان حساب، تازه از پایگاه‌داده — با زمینه‌ی **همین درخواست**. `fresh()` ِ خالی پیش‌فرضِ حساب را برمی‌گرداند و
     * پاسخی که نقش و مجتمع دارد (پروفایل) زمینه‌ی دستگاهِ دیگری را نشان می‌داد.
     */
    public static function fresh(User $user): User
    {
        $fresh = $user->fresh() ?? $user;
        $fresh->forceFill(['complex_id' => $user->complex_id, 'role' => $user->role]);
        $fresh->syncOriginalAttributes(['complex_id', 'role']);
        if ($user->relationLoaded('complex')) {
            $fresh->setRelation('complex', $user->getRelation('complex'));
        }

        return $fresh;
    }

    /**
     * پیش‌فرضِ حساب به دستِ خودِ همین حساب عوض شد — نشستِ همین درخواست هم. کارِ دیگران (مدیری که عضویت را
     * پایان می‌دهد، بازگشتِ بی‌نشستِ بانک) نشستِ کسی را جابه‌جا نمی‌کند؛ آن نشست‌ها در درخواستِ بعدی خودشان
     * سنجیده می‌شوند.
     */
    public static function follow(User $account): void
    {
        if (Auth::id() === $account->id && ! $account->isSuperAdmin()) {
            self::pin($account, $account->complex_id);
        }
    }
}
