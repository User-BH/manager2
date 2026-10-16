<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

/**
 * مجتمعِ انتخاب‌شده‌ی **همین مرورگر** (مرحلهٔ ۱۲ · فاز ۱؛ بندهای ۴ و ۱۸ ِ سندِ هسته).
 *
 * ─── چرا جدا از حساب ──────────────────────────────────────────────────────
 * تا ۱۲/۱ زمینه فقط روی ردیفِ حساب بود (`users.complex_id`، `users.role`): تعویضِ مجتمع روی یک دستگاه زمینه‌ی
 * دستگاهِ دیگرِ همان حساب را هم عوض می‌کرد، و «نقش» شکلِ ویژگیِ دائمیِ حساب داشت. حالا:
 *
 *   حساب ← **مجتمعِ این مرورگر** ← عضویتِ فعال ← نقشِ همان عضویت ← مجوز ← داشبورد
 *
 * ستون‌های حساب «پیش‌فرض» اند: مرورگرِ تازه با همان شروع می‌شود و در اولین درخواست به آن سنجاق می‌شود؛ از آن پس
 * تغییرِ پیش‌فرض (تعویض روی دستگاهِ دیگر) این مرورگر را جابه‌جا نمی‌کند. نویسنده‌ی پیش‌فرض همچنان فقط
 * `MembershipLedger` است و وقتی کاری از **خودِ** همین حساب زمینه‌اش را عوض می‌کند (تعویض، پذیرشِ دعوت، خرید)،
 * سنجاقِ همان درخواست را هم دنبالش می‌برد (`follow`).
 *
 * ─── چرا کوکیِ جدا، نه دادهٔ نشست ──────────────────────────────────────────
 * 🔴 CI ِ `40e19dc` (Firefox، `context-switch`) نشان داد: لاراول **کلِ** دادهٔ نشست را در پایانِ هر درخواست
 * دوباره می‌نویسد. درخواستی که پیش از تعویض شروع شده بود (پرسشِ اعلان‌ها) و پس از آن تمام شد، سنجاقِ قدیم را روی
 * سنجاقِ تازه نوشت؛ پاسخِ بعدی زمینه‌ی قبلی را اعلام کرد و رابط دوباره از نو بار شد. کوکیِ جدا فقط وقتی سنجاق
 * **عوض می‌شود** فرستاده می‌شود؛ درخواستِ دیگری که آن را عوض نکرده چیزی رویش نمی‌نویسد.
 *
 * اختیار از سنجاق نمی‌آید: کوکی رمزنگاری‌شده است (`EncryptCookies`)، به شناسه‌ی حساب بسته است، و
 * `SetCurrentComplex` روی هر درخواست عضویتِ فعال را می‌سنجد و نقش را از **همان عضویت** می‌گیرد. سنجاقِ مجتمعی که
 * عضویتش پایان یافته یا معلق شده به پیش‌فرض برمی‌گردد.
 */
final class SessionContext
{
    public const COOKIE = 'sakena_context';

    /** سنجاقِ همین درخواست پس از تغییر — تا درخواستِ جاری مقدارِ تازه را ببیند، نه کوکیِ ورودی */
    private const CURRENT = 'sakena.context_pin';

    /** یک ماه: مرورگری که هر روز باز می‌شود مجتمعِ آخرش را نگه می‌دارد؛ اعتبار همیشه از عضویت */
    private const MINUTES = 60 * 24 * 30;

    /** مجتمعِ سنجاق‌شده‌ی این مرورگر برای همین حساب، یا null */
    public static function pinned(User $user): ?int
    {
        $request = request();
        $value = $request->attributes->has(self::CURRENT)
            ? $request->attributes->get(self::CURRENT)
            : $request->cookie(self::COOKIE);

        if (! is_string($value) || preg_match('/^(\d+):(\d+)$/', $value, $m) !== 1 || (int) $m[1] !== $user->id) {
            return null;
        }

        return (int) $m[2];
    }

    public static function pin(User $user, ?int $complexId): void
    {
        $value = $complexId === null ? null : $user->id.':'.$complexId;
        $request = request();

        // فراخواننده فقط وقتی صدا می‌زند که سنجاق عوض می‌شود (`SetCurrentComplex`، دفترِ عضویت) — همان دلیلِ جدا بودن
        $request->attributes->set(self::CURRENT, $value);
        Cookie::queue($value === null ? Cookie::forget(self::COOKIE) : Cookie::make(self::COOKIE, $value, self::MINUTES));
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
     * پیش‌فرضِ حساب به دستِ خودِ همین حساب عوض شد — سنجاقِ همین مرورگر هم. کارِ دیگران (مدیری که عضویت را پایان
     * می‌دهد، بازگشتِ بی‌نشستِ بانک) سنجاقِ کسی را جابه‌جا نمی‌کند؛ آن مرورگرها در درخواستِ بعدیِ خودشان سنجیده
     * می‌شوند.
     */
    public static function follow(User $account): void
    {
        if (Auth::id() === $account->id && ! $account->isSuperAdmin()) {
            self::pin($account, $account->complex_id);
        }
    }
}
