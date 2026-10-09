/**
 * توکنِ پیوندِ دعوت (`/invite#…`) — یک بار خوانده و **همان لحظه** از نشانی پاک
 * می‌شود (مرحلهٔ ۹ · اصلاحاتِ پس از فاز ۶؛ تصمیمِ کارفرما: «توکن به‌صورت
 * plaintext در لاگ، ابزارِ تحلیل یا گزارشِ خطا نیاید»).
 *
 * ⚠️ پیش از این صفحه‌ی دعوت خودش در `useEffect` نشانی را پاک می‌کرد؛ یعنی
 * پس از راه‌افتادنِ پایش و تحلیل در نقطه‌ی ورود. هر چه در آن فاصله نشانی را
 * می‌خواند (اسکریپتِ تحلیلی، گزارشِ خطای زودهنگام) توکن را می‌دید. حالا
 * نقطه‌ی ورود **پیش از `initObservability`** این را صدا می‌زند.
 */
let captured: string | null = null

export function takeInviteTokenFromUrl(): void {
  if (window.location.pathname !== '/invite' || !window.location.hash) return

  captured = window.location.hash.replace(/^#/, '')
  window.history.replaceState(null, '', window.location.pathname)
}

/** توکنِ گرفته‌شده؛ اگر هنوز گرفته نشده (مثلاً در آزمون)، همین حالا. */
export function inviteToken(): string {
  takeInviteTokenFromUrl()

  return captured ?? ''
}
