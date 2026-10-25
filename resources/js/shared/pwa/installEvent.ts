import { markInstalled, markNotInstalled, mayOffer, runningInstalled } from './installState'

/**
 * یک نسخه از رویدادِ نصبِ مرورگر برای همه‌ی صفحه (مرحلهٔ ۱۳ · فاز ۲).
 *
 * ─── چرا یک انباره، نه یک هوک در هر جا ─────────────────────────────────────
 * از ۱۳/۲ دو مصرف‌کننده روی یک صفحه‌اند: کارتِ شناورِ پیشنهادِ نصب و دکمه‌ی نصبِ Hero. `beforeinstallprompt`
 * یک‌بارمصرف است: `prompt()` ِ دوم روی همان رویداد خطا می‌دهد. اگر هر کدام نسخه‌ی خودش را نگه می‌داشت، پس از نصب یا
 * انصراف از یکی، دیگری رویدادِ مصرف‌شده را نگه می‌داشت و کلیکِ بعدی خطای JavaScript می‌ساخت. این‌جا رویداد یک جا
 * گرفته و با `promptInstall()` یک بار مصرف می‌شود؛ هر دو همان وضعیت را می‌بینند.
 *
 * ─── چرا در سطحِ ماژول ─────────────────────────────────────────────────────
 * مرورگر رویداد را هر لحظه‌ای ممکن است بفرستد — حتی پیش از سوارشدنِ React. شنونده با بارگذاریِ همین ماژول وصل
 * می‌شود.
 *
 * `offerCard`: آیا کارتِ شناور اجازه‌ی نمایش دارد (فاصله‌ی تکرار و «نه، ممنون»؛ `installState.ts`). همان لحظه‌ی رسیدنِ
 * رویداد سنجیده می‌شود، همان رفتارِ پیشین. دکمه‌ی Hero درخواستِ صریحِ کاربر است و به آن بسته نیست.
 */

/** رویدادی که تایپ‌اسکریپت نمی‌شناسد چون هنوز در استاندارد نیست. */
export interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

export interface InstallSnapshot {
  /** مرورگر همین حالا نصب را پیشنهاد داده و رویدادش مصرف نشده */
  event: BeforeInstallPromptEvent | null
  /** کارتِ شناور برای همین رویداد اجازه دارد */
  offerCard: boolean
  /** داخلِ اپِ نصب‌شده اجرا می‌شود، یا مرورگر همین حالا `appinstalled` فرستاد */
  installed: boolean
}

let snapshot: InstallSnapshot = { event: null, offerCard: false, installed: runningInstalled() }
const listeners = new Set<() => void>()

function set(next: Partial<InstallSnapshot>): void {
  snapshot = { ...snapshot, ...next }
  listeners.forEach((listener) => listener())
}

if (typeof window !== 'undefined') {
  // داخلِ اپ: یادِ این انباره بماند که اپ این‌جا هست (فایرفاکسِ اندروید از آن سود می‌برد)
  if (snapshot.installed) markInstalled()

  window.addEventListener('beforeinstallprompt', (native) => {
    /*
     * جلوی نوارِ پیش‌فرضِ مرورگر گرفته می‌شود و خودِ رویداد نگه داشته می‌شود؛ بعداً فقط با ژستِ کاربر می‌توان
     * `prompt()` را صدا زد. نصب از نشانِ نوارِ آدرس همچنان در دسترس است.
     */
    native.preventDefault()
    // مرورگر خودش می‌گوید نصب نیست — هر نشانه‌ی قدیمیِ «نصب‌شده» کهنه است
    markNotInstalled()
    set({
      event: native as BeforeInstallPromptEvent,
      offerCard: mayOffer('native'),
      installed: false,
    })
  })

  window.addEventListener('appinstalled', () => {
    markInstalled()
    set({ event: null, offerCard: false, installed: true })
  })
}

export function subscribeInstall(listener: () => void): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

export function installSnapshot(): InstallSnapshot {
  return snapshot
}

/**
 * پنجره‌ی نصبِ واقعیِ مرورگر — فقط با ژستِ کاربر. رویداد پیش از `prompt()` از انباره برداشته می‌شود تا هیچ
 * مصرف‌کننده‌ی دیگری دوباره صدایش نزند.
 *
 * ⚠️ `accepted` یعنی کاربر «نصب» را زد، نه این‌که نصب تمام شد؛ «نصب‌شده» فقط با `appinstalled` ِ مرورگر.
 */
export async function promptInstall(): Promise<'accepted' | 'dismissed' | 'unavailable'> {
  const { event } = snapshot
  if (!event) return 'unavailable'

  set({ event: null, offerCard: false })
  await event.prompt()
  const { outcome } = await event.userChoice

  return outcome
}
