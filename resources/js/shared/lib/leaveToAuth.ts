/**
 * رفتن به صفحه‌ی ورود — فقط **یک** ناوبری، هرچند چند جا هم‌زمان بخواهند
 * (مرحلهٔ ۵ · فاز ۸ · بندِ ۶۴).
 *
 * ─── 🔴 چه چیزی خراب بود ─────────────────────────────────────────────────
 * خروج دو ناوبری می‌ساخت: `logout()` کاربر را پاک می‌کرد، `ProtectedRoute`
 * همان لحظه `/auth` را باز می‌کرد، و `UserMenu` بلافاصله دوباره. آزمونِ
 * مرورگر دو بار بارشدنِ `/auth` را شمرد؛ Firefox اولی را نیمه‌کاره لغو
 * می‌کرد (`NS_BINDING_ABORTED`).
 *
 * ⚠️ ناوبریِ اول برنده است: اگر حسابِ غیرفعال‌شده با دلیل بیرون برود،
 * `ProtectedRoute` پیام را با یک `/auth`ِ خالی جایگزین نمی‌کند.
 *
 * `replace`، نه `assign`: «بازگشت» پس از خروج نباید به داشبورد برگردد.
 *
 * ─── 🔴 دلیل، کد است نه متن (مرحلهٔ ۵ · فاز ۱۰) ───────────────────────────
 * پیش از این متنِ فارسیِ پیام در آدرس می‌رفت (`?reason=...`) و صفحه‌ی ورود
 * **هر متنی** را در آن نوار نشان می‌داد. یعنی پیوندی مثلِ
 * `/auth?reason=حساب مسدود شد، با ۰۹۱۲… تماس بگیرید` روی دامنه‌ی خودمان
 * پیامِ جعلیِ مهاجم را با نشانِ هشدار نشان می‌داد. حالا فقط کدهای شناخته
 * پذیرفته می‌شوند و متن در همین فایل است.
 */
export type AuthLeaveReason = 'session_ended' | 'session_expired' | 'account_disabled'

const MESSAGES: Record<AuthLeaveReason, string> = {
  session_ended:
    'نشستِ شما بسته شد — شاید از دستگاهِ دیگری خارج شده‌اید یا رمز عوض شده است. دوباره وارد شوید.',
  session_expired: 'زمانِ نشستِ شما به پایان رسید. برای امنیتِ حساب دوباره وارد شوید.',
  account_disabled: 'حساب کاربری شما غیرفعال شده است. با مدیر ساختمان تماس بگیرید.',
}

/** متنِ نوارِ صفحه‌ی ورود، فقط برای کدِ شناخته؛ هر چیزِ دیگر `null`. */
export function authLeaveMessage(code: string | null): string | null {
  return code !== null && Object.hasOwn(MESSAGES, code) ? MESSAGES[code as AuthLeaveReason] : null
}

let leaving = false

export function leaveToAuth(
  reason?: AuthLeaveReason,
  navigate: (url: string) => void = (url) => window.location.replace(url),
): void {
  if (leaving) return
  leaving = true

  navigate(reason ? `/auth?reason=${reason}` : '/auth')
}

/** فقط برای تست: سند در مرورگر پس از ناوبری از نو ساخته می‌شود. */
export function resetLeaveToAuth(): void {
  leaving = false
}
