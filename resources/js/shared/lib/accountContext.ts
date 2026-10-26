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
let entering = false

export async function enterNewContext(path = '/dashboard'): Promise<void> {
  // یک بار: چند پاسخِ هم‌زمان با زمینه‌ی تازه چند ناوبری نمی‌سازند
  if (entering) return
  entering = true
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

/**
 * «ایجادِ حسابِ جدید» (مرحلهٔ ۱۳ · فاز ۳): همین مرورگر به همان داشبوردِ اولیه با همان حساب می‌رود. سرور فقط سنجاقِ
 * این مرورگر را عوض می‌کند؛ مجتمع‌ها سرِ جایشان‌اند و بازگشت با `switchContext` است، بی خروج. ناوبریِ کامل به همان
 * دلیلِ تعویض: هیچ چیزِ مجتمعِ قبلی در رابط نمی‌ماند.
 */
export async function enterInitialContext(path = '/dashboard'): Promise<void> {
  await api('/account/context/initial', { method: 'POST' })
  await enterNewContext(path)
}

/**
 * مقصدی که فقط داشبوردِ اولیه دارد (۱۳/۵): دعوت‌های مدیرانِ مجتمع‌های دیگر. از داشبوردِ مجتمع با همان راهِ «ایجادِ حسابِ
 * جدید» (`enterInitialContext`) به آن می‌رسیم (ایرادات ۱۱، بندِ ۳.۴: «کاربر وارد داشبوردِ ثبت‌نامِ اولیه می‌شود و درخواست
 * را بررسی می‌کند»).
 */
export const INVITATIONS_PATH = '/invitations'

/** رفتن به مقصدِ یک اعلان یا گزینه: دعوت‌ها از داشبوردِ مجتمع با زمینه‌ی اولیه، بقیه با ناوبریِ درونِ همین داشبورد */
export function openTarget(
  path: string,
  user: { dashboard?: string } | null,
  navigate: (path: string) => void,
): void {
  if (path === INVITATIONS_PATH && user?.dashboard !== 'initial') {
    void enterInitialContext(path)
    return
  }
  navigate(path)
}

/**
 * زمینه‌ای که سرور اعلام کرد با آن‌چه رابط نشان می‌دهد نمی‌خواند (مرحلهٔ ۱۲ · فاز ۱) — ورودِ کامل به زمینه‌ی
 * تازه، همان راهِ تعویضِ مجتمع. ادمینِ کل و مهمان زمینه‌ی عضویتی ندارند. دو زمینه‌ی بی‌مجتمعِ حسابِ عضو (۱۳/۳)
 * نامِ خودشان را دارند: `0:choose` (صفحه‌ی انتخاب) و `0:initial` (داشبوردِ اولیه).
 */
export function contextMismatch(
  declared: string,
  user: {
    isSuperAdmin: boolean
    role: string
    dashboard?: string
    complex: { id: number } | null
  } | null,
): boolean {
  if (!user || user.isSuperAdmin) return false
  return declared !== expectedContext(user)
}

function expectedContext(user: {
  role: string
  dashboard?: string
  complex: { id: number } | null
}) {
  if (user.dashboard === 'select') return '0:choose'
  if (user.dashboard === 'initial') return '0:initial'
  return `${user.complex?.id ?? 0}:${user.role}`
}
