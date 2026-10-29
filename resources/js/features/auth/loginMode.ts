/**
 * آیا ورود فقط با نام کاربری است؟ — فقط محیطِ آزمایشیِ Preview (۲۰۲۶-۱۰-۲۹، بندِ ۴). سرور `<meta name="login-mode">`
 * را فقط آن‌جا می‌گذارد؛ پس `/auth` همان فرمِ نام کاربری است و مسیرهای شماره‌ای (ثبت‌نام، کدِ پیامکی، بازیابی) به آن
 * برمی‌گردند. مرزِ واقعی سمتِ سرور است: ورود، ثبت‌نام و بازیابی با شماره آن‌جا ۴۲۲ می‌گیرند.
 *
 * این‌جا و نه در `shared/lib/viewer`: آن ماژول مالِ صفحه‌های عمومی است و اگر ورود هم واردش کند، چانکِ ریزِ مشترکِ
 * تازه‌ای می‌سازد (`bundleBudget.test.ts`).
 */
export function isUsernameLogin(): boolean {
  if (typeof document === 'undefined') return false

  return document.querySelector('meta[name="login-mode"]')?.getAttribute('content') === 'username'
}
