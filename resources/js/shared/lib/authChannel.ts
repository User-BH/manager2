/**
 * هم‌زمانیِ ورود و خروج بینِ تب‌ها (مرحلهٔ ۵ · فاز ۱۰).
 *
 * ─── 🔴 چه چیزی خراب بود ─────────────────────────────────────────────────
 * کوکیِ نشست بینِ تب‌ها مشترک است ولی حالتِ رابط نه. کاربر در یک تب خارج
 * می‌شد و تبِ دیگر قبض‌ها و پیام‌ها را همچنان نشان می‌داد؛ یا در تبِ دیگر
 * با حسابِ دیگری وارد می‌شد و تبِ قدیمی **داده‌ی نفرِ اول را با نشستِ نفرِ
 * دوم** نشان می‌داد و هر ذخیره به نامِ نفرِ دوم می‌رفت.
 *
 * ⚠️ پیامِ کانال از بیرونِ این تب می‌آید؛ مثلِ هر ورودیِ بیرونی شکلش سنجیده
 * می‌شود. مرورگری که `BroadcastChannel` ندارد فقط این هم‌زمانی را ندارد —
 * سرور همچنان نشستِ بسته را رد می‌کند.
 */

/** `context`: زمینه‌ی مجتمعِ حساب عوض شد (مرحلهٔ ۱۰ · فاز ۴) — تب‌های دیگر از نو بار می‌شوند. */
export type AuthMessage =
  { type: 'logout' } | { type: 'context' } | { type: 'login'; userId: number }

const NAME = 'sakena-auth'

let channel: BroadcastChannel | null = null

// ⚠️ یک نمونه برای فرستادن و شنیدن: کانال پیامِ خودش را به خودش نمی‌دهد
function getChannel(): BroadcastChannel | null {
  if (typeof BroadcastChannel === 'undefined') return null
  channel ??= new BroadcastChannel(NAME)
  return channel
}

export function isAuthMessage(value: unknown): value is AuthMessage {
  if (typeof value !== 'object' || value === null) return false
  const message = value as Record<string, unknown>

  if (message.type === 'logout' || message.type === 'context') return true

  return (
    message.type === 'login' &&
    typeof message.userId === 'number' &&
    Number.isInteger(message.userId)
  )
}

export function announceAuth(message: AuthMessage): void {
  getChannel()?.postMessage(message)
}

export function listenForAuthChanges(handler: (message: AuthMessage) => void): () => void {
  const current = getChannel()
  if (!current) return () => {}

  const listener = (event: MessageEvent) => {
    if (isAuthMessage(event.data)) handler(event.data)
  }

  current.addEventListener('message', listener)
  return () => current.removeEventListener('message', listener)
}
