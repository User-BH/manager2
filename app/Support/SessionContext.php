<?php

namespace App\Support;

use App\Enums\UserRole;
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
 * ستون‌های حساب «پیش‌فرض» اند: مرورگر هنگامِ **ورود** به همان سنجاق می‌شود؛ از آن پس تغییرِ پیش‌فرض (تعویض روی
 * دستگاهِ دیگر) این مرورگر را جابه‌جا نمی‌کند. نویسنده‌ی پیش‌فرض همچنان فقط
 * `MembershipLedger` است و وقتی کاری از **خودِ** همین حساب زمینه‌اش را عوض می‌کند (تعویض، پذیرشِ دعوت، خرید)،
 * سنجاقِ همان درخواست را هم دنبالش می‌برد (`follow`).
 *
 * ─── چرا کوکیِ جدا، نه دادهٔ نشست ──────────────────────────────────────────
 * 🔴 CI ِ `40e19dc` (Firefox، `context-switch`) نشان داد: لاراول **کلِ** دادهٔ نشست را در پایانِ هر درخواست
 * دوباره می‌نویسد. درخواستی که پیش از تعویض شروع شده بود (پرسشِ اعلان‌ها) و پس از آن تمام شد، سنجاقِ قدیم را روی
 * سنجاقِ تازه نوشت. 🔴 CI ِ `69a7d21` (Chromium و Firefox) نشان داد کوکی هم اگر در هر درخواستی نوشته شود همان
 * مسابقه را دارد: `Set-Cookie` ِ پاسخِ دیر روی سنجاقِ تعویض نشست. پس سنجاق **فقط** در کارهای پی‌درپیِ خودِ کاربر
 * نوشته می‌شود — ورود و `follow` (تعویض، پذیرش، خرید) — و هیچ درخواستِ دیگری کوکیِ سنجاق نمی‌فرستد.
 *
 * اختیار از سنجاق نمی‌آید: کوکی رمزنگاری‌شده است (`EncryptCookies`)، به شناسه‌ی حساب بسته است، و
 * `SetCurrentComplex` روی هر درخواست عضویتِ فعال را می‌سنجد و نقش را از **همان عضویت** می‌گیرد. سنجاقِ مجتمعی که
 * عضویتش پایان یافته یا معلق شده به پیش‌فرض برمی‌گردد.
 *
 * ─── دو زمینه‌ی بی‌مجتمع (مرحلهٔ ۱۳ · فاز ۳) ─────────────────────────────────────────────
 * سنجاق جز مجتمع یکی از دو حالت هم می‌تواند باشد، هر دو برای حسابِ عضو و هر دو بی هیچ داده‌ی مجتمعی
 * (`TenantContext::denyAll`، همان قفلِ حالتِ اولیه):
 *
 *   - `choose`: حسابِ چندمجتمعی تازه وارد شده و هنوز مجتمعی برنگزیده (ایرادات ۱۰، بندهای ۳۳ تا ۳۵). هیچ مجتمعی
 *     خودکار انتخاب نمی‌شود؛ فقط گزینش (`POST account/context`) و خروج باز است.
 *   - `initial`: «ایجادِ حسابِ جدید» از منوی آدمک (ایرادات ۱۱، بندهای ۵.۶ تا ۵.۸) — همان داشبوردِ اولیه با همان
 *     Account. پیش‌فرضِ حساب دست نمی‌خورد و بازگشت به هر مجتمع بی خروج است.
 */
final class SessionContext
{
    public const COOKIE = 'sakena_context';

    /** حسابِ چندمجتمعیِ تازه‌واردشده: صفحه‌ی انتخاب، بی مجتمعِ خودکار */
    public const CHOOSING = 'choose';

    /** زمینه‌ی بی‌مجتمعِ حسابِ عضو: داشبوردِ اولیه */
    public const DETACHED = 'initial';

    /** سنجاقِ همین درخواست پس از تغییر — تا درخواستِ جاری مقدارِ تازه را ببیند، نه کوکیِ ورودی */
    private const CURRENT = 'sakena.context_pin';

    /** حالتِ بی‌مجتمعی که همین درخواست واقعاً در آن است (`detach`) */
    private const MODE = 'sakena.context_mode';

    /** یک ماه: مرورگری که هر روز باز می‌شود مجتمعِ آخرش را نگه می‌دارد؛ اعتبار همیشه از عضویت */
    private const MINUTES = 60 * 24 * 30;

    /** مجتمعِ سنجاق‌شده‌ی این مرورگر برای همین حساب، یا null */
    public static function pinned(User $user): ?int
    {
        $match = self::match($user, '\d+');

        return $match === null ? null : (int) $match;
    }

    /** حالتِ بی‌مجتمعِ سنجاق‌شده‌ی این مرورگر برای همین حساب (`choose` یا `initial`)، یا null */
    public static function mode(User $user): ?string
    {
        return self::match($user, self::CHOOSING.'|'.self::DETACHED);
    }

    /** @param  int|self::CHOOSING|self::DETACHED|null  $target */
    public static function pin(User $user, int|string|null $target): void
    {
        $value = $target === null ? null : $user->id.':'.$target;
        $request = request();

        // فراخواننده فقط وقتی صدا می‌زند که سنجاق عوض می‌شود (`SetCurrentComplex`، دفترِ عضویت) — همان دلیلِ جدا بودن
        $request->attributes->set(self::CURRENT, $value);
        // سنجاقِ تازه حالتِ بی‌مجتمعِ پیشینِ همین درخواست را هم می‌برد؛ رفتن به حالتِ تازه با `detach` است
        $request->attributes->remove(self::MODE);
        Cookie::queue($value === null ? Cookie::forget(self::COOKIE) : Cookie::make(self::COOKIE, $value, self::MINUTES));
    }

    /**
     * همین درخواست بی مجتمع: حساب برای این درخواست مثلِ حسابِ اولیه است (نه نوشته در پایگاه‌داده؛ همان راهی که
     * `SetCurrentComplex` مجتمعِ نشست را روی نمونه‌ی حساب می‌گذارد). قفلِ حالتِ اولیه و `TenantContext::denyAll`
     * دامنه را می‌بندند؛ پیش‌فرضِ حساب (`users.complex_id`) دست نمی‌خورد.
     */
    public static function detach(User $user, string $mode): void
    {
        // نقشِ حسابِ بی‌مجتمع همان است که ثبت‌نام و پایانِ آخرین عضویت می‌دهند (`MembershipLedger::fallBack`)
        $user->forceFill(['complex_id' => null, 'role' => UserRole::Owner]);
        $user->syncOriginalAttributes(['complex_id', 'role']);
        $user->setRelation('complex', null);

        request()->attributes->set(self::MODE, $mode);
    }

    /** حالتِ بی‌مجتمعی که همین درخواست در آن است (`choose`، `initial`)، یا null */
    public static function detachedAs(): ?string
    {
        $mode = request()->attributes->get(self::MODE);

        return is_string($mode) ? $mode : null;
    }

    private static function match(User $user, string $target): ?string
    {
        $request = request();
        $value = $request->attributes->has(self::CURRENT)
            ? $request->attributes->get(self::CURRENT)
            : $request->cookie(self::COOKIE);

        if (! is_string($value) || preg_match('/^(\d+):('.$target.')$/', $value, $m) !== 1 || (int) $m[1] !== $user->id) {
            return null;
        }

        return $m[2];
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
