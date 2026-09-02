import { create } from 'zustand'

/**
 * وضعیتِ واقعیِ اتصال (R62).
 *
 * ─── 🔴 چه چیزی نبود ───────────────────────────────────────────────────────
 * ⚠️ اندازه‌گیری شد: در کلِ فرانت **یک بار هم** `navigator.onLine` خوانده
 * نمی‌شد. یعنی کاربرِ آفلاین هیچ نشانه‌ای نمی‌دید؛ فقط درخواست‌هایش یکی‌یکی
 * با پیامِ عمومیِ «ارتباط با سرور برقرار نشد» شکست می‌خوردند و او
 * نمی‌دانست مشکل از اوست یا از ما.
 *
 * ─── چرا فقط `navigator.onLine` کافی نیست ──────────────────────────────────
 * ⚠️ آن پرچم فقط می‌گوید «کارتِ شبکه به چیزی وصل است»، نه «اینترنت
 * داری». وصل‌بودن به مودمی که خودش قطع است، `true` می‌دهد — و آن‌وقت
 * نشانگرِ ما به کاربر دروغ می‌گوید.
 *
 * ⚠️ ولی جهتِ **منفی**اش قابلِ اعتماد است: اگر `false` بگوید، واقعاً
 * قطعی است.
 *
 * پس دو منبع با هم:
 *
 *   ① رویدادهای `online`/`offline` مرورگر — سریع، برای قطعِ واقعی
 *   ② نتیجه‌ی **درخواست‌های واقعی** — تنها حقیقتِ سنجیده‌شده
 *
 * منبعِ دوم مهم‌تر است: پاسخِ موفق یعنی قطعاً آنلاینیم، و شکستِ شبکه‌ای
 * (`status === 0`) یعنی قطعاً نیستیم — فارغ از اینکه پرچمِ مرورگر چه
 * می‌گوید.
 */

export type ConnectionState = 'online' | 'offline' | 'retrying'

interface ConnectionStore {
  state: ConnectionState
  /** آخرین نوشتنی که به‌خاطرِ قطعی انجام نشد — برای نشان‌دادنِ «ناموفق». */
  lastWriteFailed: boolean
}

export const useConnectionStore = create<ConnectionStore>(() => ({
  state: typeof navigator !== 'undefined' && navigator.onLine === false ? 'offline' : 'online',
  lastWriteFailed: false,
}))

/** پاسخِ موفق رسید: قطعاً آنلاینیم. */
export function markOnline(): void {
  const { state, lastWriteFailed } = useConnectionStore.getState()

  if (state === 'online' && !lastWriteFailed) return

  useConnectionStore.setState({ state: 'online', lastWriteFailed: false })
}

/**
 * درخواستی به سرور نرسید.
 *
 * ⚠️ `writeFailed` جدا نگه داشته می‌شود چون معنیِ متفاوتی دارد: خواندنِ
 * ناموفق فقط یعنی «الان نمی‌بینی»، ولی نوشتنِ ناموفق یعنی **کاری که
 * کاربر خواسته انجام نشده** — و او باید بداند.
 */
export function markOffline(writeFailed = false): void {
  useConnectionStore.setState((current) => ({
    state: 'offline',
    lastWriteFailed: current.lastWriteFailed || writeFailed,
  }))
}

/** یک درخواستِ در حالِ تلاشِ دوباره. */
export function markRetrying(): void {
  if (useConnectionStore.getState().state === 'offline') return

  useConnectionStore.setState({ state: 'retrying' })
}

/**
 * آیا مرورگر **قطعاً** می‌گوید آفلاینیم؟
 *
 * ⚠️ فقط به جهتِ منفی اعتماد می‌کنیم. `true`ِ این پرچم چیزی را ثابت
 * نمی‌کند و نباید مبنای هیچ تصمیمی باشد.
 */
export function definitelyOffline(): boolean {
  return typeof navigator !== 'undefined' && navigator.onLine === false
}

/**
 * وصل‌کردن به رویدادهای مرورگر.
 *
 * ⚠️ رویدادِ `online` **آنلاین‌بودن را ثابت نمی‌کند** (همان تله‌ی بالا)،
 * پس فقط حالتِ «شاید برگشته» می‌سازد و تأییدِ نهایی با اولین پاسخِ موفق
 * می‌آید. برعکسش، `offline` قطعی است و بی‌درنگ اعمال می‌شود.
 */
export function watchConnection(): () => void {
  if (typeof window === 'undefined') return () => {}

  const goOffline = () => markOffline()
  const maybeOnline = () => useConnectionStore.setState({ state: 'retrying' })

  window.addEventListener('offline', goOffline)
  window.addEventListener('online', maybeOnline)

  return () => {
    window.removeEventListener('offline', goOffline)
    window.removeEventListener('online', maybeOnline)
  }
}

/** برای تست‌ها. */
export function resetConnectionState(): void {
  useConnectionStore.setState({ state: 'online', lastWriteFailed: false })
}
