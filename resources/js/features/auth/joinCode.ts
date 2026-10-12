/**
 * کدِ QR ِ ورودِ عمومی (`/join#…`؛ مرحلهٔ ۱۰ · فاز ۵) — از نشانی برداشته و تا پایانِ ثبت‌نام یا ورود نگه داشته
 * می‌شود.
 *
 * برخلافِ توکنِ دعوت (`inviteToken`) این کد راز نیست — روی QR ِ لابی چاپ شده — ولی همان‌طور پیش از پایش و
 * تحلیل از نوارِ نشانی پاک می‌شود. چون ورود یک ناوبریِ کاملِ مرورگر است (سندِ داشبورد جداست)، حافظه‌ی ماژول
 * کافی نیست: در `sessionStorage` ِ همین تب می‌ماند تا پس از ورود صفحه‌ی پیوستن دوباره باز شود.
 *
 * ⚠️ این فقط **مسیریابیِ رابط** است، نه مجوز: کد را سرور در `join-link/preview` و `join-requests` می‌سنجد و
 * درخواست تا تأییدِ مدیر هیچ عضویتی نمی‌سازد. دستکاریِ این حافظه حداکثر صفحه‌ی «QR معتبر نیست» را نشان می‌دهد.
 */
const KEY = 'sakena.joinCode'
const PATTERN = /^[A-Za-z0-9_-]{22}$/

export function takeJoinCodeFromUrl(): void {
  if (window.location.pathname !== '/join' || !window.location.hash) return

  const code = window.location.hash.replace(/^#/, '')
  window.history.replaceState(null, '', window.location.pathname)

  try {
    if (PATTERN.test(code)) sessionStorage.setItem(KEY, code)
    else sessionStorage.removeItem(KEY)
  } catch {
    // حافظه‌ی تب در دسترس نیست (حالتِ خصوصیِ سخت‌گیر): پس از ورود صفحه‌ی پیوستن خودش باز نمی‌شود
  }
}

/** کدِ نگه‌داشته‌ی همین تب، یا رشته‌ی خالی. */
export function joinCode(): string {
  takeJoinCodeFromUrl()

  try {
    const code = sessionStorage.getItem(KEY) ?? ''
    return PATTERN.test(code) ? code : ''
  } catch {
    return ''
  }
}

export function forgetJoinCode(): void {
  try {
    sessionStorage.removeItem(KEY)
  } catch {
    // چیزی برای پاک‌کردن نیست
  }
}

/** پس از ثبت‌نام یا ورود: اگر کسی از QR آمده، به فرستادنِ درخواست برمی‌گردد؛ وگرنه داشبورد. */
export function afterSignIn(): string {
  return joinCode() ? '/join' : '/dashboard'
}
