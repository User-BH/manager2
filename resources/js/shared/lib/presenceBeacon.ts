import { currentCsrfToken } from './http'

/**
 * «آفلاین شدم» هنگامِ بستنِ صفحه — «چند نکته»، بندِ ۳.
 *
 * ضربانِ حضور خودِ pollingهای موجود است و درخواستِ تازه‌ای نمی‌خواهد؛ این تنها درخواستی است که حضور اضافه کرد،
 * و فقط یک بار، وقتی داشبورد بسته یا ترک می‌شود. `fetch` ِ keepalive چون پس از `pagehide` درخواستِ عادی بریده
 * می‌شود. اگر نرسد — موبایل همیشه `pagehide` نمی‌دهد، شبکه قطع است — پنجره‌ی ۹۰ ثانیه‌ی سرور همان کار را دیرتر
 * می‌کند. بازگشت از bfcache یا تبِ دیگرِ هنوز باز با پرسشِ بعدی‌شان دوباره آنلاین می‌شوند.
 */
let registered = false

export function sendOffline(): void {
  try {
    void fetch('/api/v1/presence/offline', {
      method: 'POST',
      keepalive: true,
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': currentCsrfToken(),
      },
    }).catch(() => undefined)
  } catch {
    // مرورگرِ بی keepalive: پنجره‌ی سرور کافی است
  }
}

/** یک بار برای کلِ صفحه — داشبورد هر بار mount شود دوباره ثبت نمی‌شود */
export function registerPresenceBeacon(): void {
  if (registered || typeof window === 'undefined') return
  registered = true
  window.addEventListener('pagehide', sendOffline)
}
