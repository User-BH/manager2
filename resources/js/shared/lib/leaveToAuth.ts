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
 * ⚠️ ناوبریِ اول برنده است: اگر حسابِ غیرفعال‌شده با `reason` بیرون برود،
 * `ProtectedRoute` پیام را با یک `/auth`ِ خالی جایگزین نمی‌کند.
 *
 * `replace`، نه `assign`: «بازگشت» پس از خروج نباید به داشبورد برگردد.
 */
let leaving = false

export function leaveToAuth(
  reason?: string,
  navigate: (url: string) => void = (url) => window.location.replace(url),
): void {
  if (leaving) return
  leaving = true

  navigate(reason ? `/auth?reason=${encodeURIComponent(reason)}` : '/auth')
}

/** فقط برای تست: سند در مرورگر پس از ناوبری از نو ساخته می‌شود. */
export function resetLeaveToAuth(): void {
  leaving = false
}
