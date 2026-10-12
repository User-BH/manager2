import { api } from './api'
import { announceAuth } from './authChannel'
import { clearApiCache } from '@/shared/pwa/registerServiceWorker'

/**
 * ورود به زمینه‌ی مجتمعِ تازه — پس از تعویضِ مجتمع یا پذیرفتنِ عضویت (مرحلهٔ ۱۰ · فاز ۴؛
 * بندهای ۱۴، ۸۵ و ۱۲۲ ِ فایلِ معماری).
 *
 * زمینه مالِ **حساب** است، نه تب: سرور از همین لحظه به همه‌ی درخواست‌ها با مجتمعِ تازه
 * و نقشِ عضویتِ آن‌جا پاسخ می‌دهد. پس هیچ چیزِ مجتمعِ قبلی نباید در رابط بماند:
 *
 *   ① کشِ پاسخ‌های API ِ Service Worker پاک می‌شود؛
 *   ② تب‌های دیگرِ همین حساب با کانالِ احراز هویت خبر می‌گیرند و از نو بار می‌شوند؛
 *   ③ همین تب با ناوبریِ کامل به داشبورد می‌رود — کشِ React Query، storeهای Zustand،
 *      حالتِ محلی، نتیجه‌ی جست‌وجو، گفت‌وگوهای پیام‌رسان، واحدِ انتخاب‌شده و اعلان‌ها
 *      همه با حافظه‌ی صفحه می‌روند و از سرور، برای زمینه‌ی تازه، دوباره خوانده می‌شوند.
 *
 * ناوبریِ کامل عمدی است، نه تنبلی: پاک‌کردنِ دستیِ تک‌تکِ stateها یعنی فهرستی که با هر
 * قابلیتِ تازه کهنه می‌شود. همان الگوی خروج از حساب (`leaveToAuth`).
 */
export async function enterNewContext(path = '/dashboard'): Promise<void> {
  await clearApiCache()
  announceAuth({ type: 'context' })
  window.location.replace(path)
}

/**
 * رفتن به مجتمعی که حساب در آن عضویتِ فعال دارد؛ اجازه را سرور از همان عضویت می‌دهد. `path` فقط برای
 * بازگشت از خرید است (۱۰/۵) که پیامِ موفقیت را به داشبوردِ مجتمعِ تازه می‌برد.
 */
export async function switchContext(complexId: number, path = '/dashboard'): Promise<void> {
  await api('/account/context', { method: 'POST', body: { complex_id: complexId } })
  await enterNewContext(path)
}
