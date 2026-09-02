import { useEffect, useState } from 'react'
import { CloudOff, RefreshCw, Wifi } from 'lucide-react'

import { useConnectionStore, watchConnection } from './connection'

/**
 * نشانگرِ وضعیتِ اتصال (R62).
 *
 * ─── 🔴 چه چیزی نبود ───────────────────────────────────────────────────────
 * ⚠️ اندازه‌گیری شد: **هیچ نشانگرِ وضعیتی وجود نداشت** و در کلِ فرانت یک بار
 * هم `navigator.onLine` خوانده نمی‌شد. کاربرِ آفلاین فقط می‌دید که
 * درخواست‌هایش یکی‌یکی با پیامِ عمومی شکست می‌خورند و نمی‌دانست مشکل از
 * اوست یا از ما.
 *
 * ─── ⚠️ چرا فقط سه حالت و نه پنج ───────────────────────────────────────────
 * فهرستِ ممیزی پنج حالت می‌خواست:
 *
 *     🟢 Online · 🔴 Offline · 🔄 Syncing · ✓ Synced · ⚠ Sync Failed
 *
 * سه تای آخر **صفِ همگام‌سازی** را فرض می‌گیرند. این سامانه صفِ آفلاین
 * ندارد و عمداً هم ندارد (دلیلش در `docs/OFFLINE.md`). ساختنِ حالتی به
 * نامِ «در حال همگام‌سازی» وقتی چیزی برای همگام‌سازی نیست، یعنی نشانگری
 * که دروغ می‌گوید — و بندِ ۱۳۶ همان فایل دقیقاً همین را ممنوع کرده.
 *
 * پس همان معناها به چیزی که **واقعاً** وجود دارد نگاشت شده‌اند:
 *
 *   🔴 آفلاین          قطعیِ واقعی
 *   🔄 بررسیِ اتصال    شبکه برگشته ولی هنوز پاسخی نگرفته‌ایم
 *   ✓ برقرار شد        اولین پاسخِ موفق پس از قطعی (چند ثانیه)
 *   ⚠ ذخیره نشد       نوشتنی که به‌خاطرِ قطعی انجام نشد
 */
export function ConnectionBanner() {
  const state = useConnectionStore((store) => store.state)
  const writeFailed = useConnectionStore((store) => store.lastWriteFailed)

  /*
   * ⚠️ گذارِ حالت در **زمانِ رندر** تشخیص داده می‌شود، نه در یک effect.
   *
   * نسخه‌ی اول این کار را داخلِ `useEffect` می‌کرد و لینتِ کامپایلرِ ری‌اکت
   * درست گیر داد: `setState`ِ همگام داخلِ effect یک رندرِ آبشاری اضافه
   * می‌سازد. مقایسه‌ی حالتِ قبلی در بدنه‌ی کامپوننت همان کار را بدونِ آن
   * رفت‌وبرگشت انجام می‌دهد — الگویی که خودِ ری‌اکت برای «واکنش به تغییرِ
   * ورودی» توصیه می‌کند.
   */
  const [seenState, setSeenState] = useState(state)
  const [recovered, setRecovered] = useState(false)

  if (state !== seenState) {
    setSeenState(state)

    // فقط گذارِ «قطع بود، حالا وصل است» خبرِ خوب دارد
    setRecovered(state === 'online' && seenState !== 'online')
  }

  useEffect(() => watchConnection(), [])

  useEffect(() => {
    if (!recovered) return

    // پیامِ «برقرار شد» نباید بماند؛ خبرِ خوب کوتاه است
    const timer = setTimeout(() => setRecovered(false), 4000)

    return () => clearTimeout(timer)
  }, [recovered])

  if (state === 'online' && !recovered && !writeFailed) return null

  const view = describe(state, writeFailed)

  return (
    <div
      role="status"
      aria-live="polite"
      /*
       * بالای صفحه و نه پایین: `PwaPrompts` پایین می‌نشیند و این دو نباید
       * روی هم بیفتند. `env(safe-area-inset-top)` برای نصبِ روی آیفون، که
       * بدونش زیرِ نوارِ وضعیت گیر می‌کند.
       */
      style={{
        top: 'calc(0.5rem + env(safe-area-inset-top, 0px))',
        backgroundColor: view.background,
        color: view.foreground,
      }}
      className="fixed inset-x-4 z-50 mx-auto flex max-w-sm items-center gap-2 rounded-xl px-3 py-2 text-xs font-medium shadow-lg"
    >
      {view.icon}
      <span>{view.text}</span>
    </div>
  )
}

/**
 * ⚠️ `recovered` عمداً پارامتر نیست.
 *
 * وقتی به شاخه‌ی آخر می‌رسیم یعنی آنلاینیم و نوشتنی جا نمانده — و بالا
 * ثابت شده که در آن حالت فقط اگر `recovered` باشد اصلاً رندری رخ می‌دهد.
 * گرفتنش به‌عنوان پارامتر یعنی همان شرط دو جا نوشته شود و روزی از هم
 * واگرا شوند.
 */
function describe(state: string, writeFailed: boolean) {
  if (state === 'offline') {
    return {
      icon: <CloudOff className="h-4 w-4 shrink-0" aria-hidden />,
      text: writeFailed ? 'اتصال قطع است — آخرین تغییر ذخیره نشد' : 'اتصال اینترنت برقرار نیست',
      background: 'var(--tone-danger-bg)',
      foreground: 'var(--tone-danger-fg)',
    }
  }

  if (state === 'retrying') {
    return {
      icon: <RefreshCw className="h-4 w-4 shrink-0 animate-spin" aria-hidden />,
      text: 'در حال بررسیِ اتصال…',
      background: 'var(--tone-warning-bg)',
      foreground: 'var(--tone-warning-fg)',
    }
  }

  /*
   * ⚠️ حالتِ باقی‌مانده: آنلاینیم ولی نوشتنی جا مانده.
   *
   * این نباید بی‌صدا برود. کاربر باید بداند کاری که خواسته انجام نشده،
   * حتی حالا که اتصال برگشته — چون ما آن را در صفی نگه نداشته‌ایم که
   * خودش بفرستد.
   */
  if (writeFailed) {
    return {
      icon: <RefreshCw className="h-4 w-4 shrink-0" aria-hidden />,
      text: 'اتصال برقرار شد — تغییرِ ذخیره‌نشده را دوباره بفرستید',
      background: 'var(--tone-warning-bg)',
      foreground: 'var(--tone-warning-fg)',
    }
  }

  return {
    icon: <Wifi className="h-4 w-4 shrink-0" aria-hidden />,
    text: 'اتصال برقرار شد',
    background: 'var(--tone-success-bg)',
    foreground: 'var(--tone-success-fg)',
  }
}
