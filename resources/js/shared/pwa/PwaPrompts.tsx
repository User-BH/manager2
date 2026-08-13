import { useEffect, useRef, useState } from 'react'
import { Download, RefreshCw, X } from 'lucide-react'

import { registerServiceWorker } from './registerServiceWorker'
import { useInstallPrompt } from './useInstallPrompt'

/**
 * دو پیامِ کوچکِ PWA: «نسخه‌ی تازه آماده است» و «نصب روی دستگاه» (R35).
 *
 * ─── چرا یک کامپوننت و نه دو ───────────────────────────────────────────────
 * هر دو در همان گوشه می‌نشینند و هیچ‌وقت نباید هم‌زمان دیده شوند: کاربری که
 * وسطِ تصمیمِ نصب است نباید نوارِ به‌روزرسانی هم رویش بیفتد. با یک کامپوننت
 * این اولویت یک `if` است، نه هماهنگیِ دو کامپوننتِ بی‌خبر از هم.
 */
export function PwaPrompts() {
  const [updateReady, setUpdateReady] = useState(false)
  const { canInstall, install, dismiss } = useInstallPrompt()

  /*
   * ⚠️ `handle` عمداً state نیست.
   *
   * اول `useState` بود و ESLint درست گیر داد: `setHandle` همان لحظه داخلِ
   * effect صدا زده می‌شد و یک رندرِ اضافه می‌ساخت، بی‌آنکه چیزی روی صفحه
   * عوض شود — چون این مقدار فقط هنگامِ کلیک خوانده می‌شود. `useRef` همان
   * کار را بدونِ رندر می‌کند.
   */
  const handle = useRef<ReturnType<typeof registerServiceWorker>>(null)

  useEffect(() => {
    const registered = registerServiceWorker()

    if (!registered) return

    handle.current = registered
    registered.onUpdateReady(() => setUpdateReady(true))
  }, [])

  // به‌روزرسانی مقدم است: کاربر باید روی نسخه‌ی درست تصمیم بگیرد
  if (updateReady) {
    return (
      <Banner
        icon={<RefreshCw className="h-4 w-4" aria-hidden />}
        title="نسخه‌ی تازه آماده است"
        body="برای اعمال، صفحه یک بار تازه می‌شود."
        actionLabel="به‌روزرسانی"
        onAction={() => handle.current?.applyUpdate()}
        onDismiss={() => setUpdateReady(false)}
      />
    )
  }

  if (canInstall) {
    return (
      <Banner
        icon={<Download className="h-4 w-4" aria-hidden />}
        title="نصب ساکنا روی دستگاه"
        body="بدون مرورگر باز می‌شود و صفحه‌های دیده‌شده آفلاین هم می‌آیند."
        actionLabel="نصب"
        onAction={() => void install()}
        onDismiss={dismiss}
      />
    )
  }

  return null
}

function Banner({
  icon,
  title,
  body,
  actionLabel,
  onAction,
  onDismiss,
}: {
  icon: React.ReactNode
  title: string
  body: string
  actionLabel: string
  onAction: () => void
  onDismiss: () => void
}) {
  return (
    <div
      role="status"
      /*
       * `bottom-safe` نداریم، پس فاصله‌ی پایین با `env(safe-area-inset-bottom)`
       * گرفته می‌شود: روی آیفونِ نصب‌شده، نوارِ خانه دقیقاً همین‌جا می‌نشیند و
       * بدونِ این، دکمه زیرش گیر می‌کند.
       */
      style={{
        bottom: 'calc(1rem + env(safe-area-inset-bottom, 0px))',
        backgroundColor: 'var(--surface-overlay)',
        borderColor: 'var(--border-subtle)',
        color: 'var(--text-primary)',
      }}
      className="fixed inset-x-4 z-50 mx-auto max-w-md rounded-2xl border p-4 shadow-xl sm:inset-x-auto sm:right-4"
    >
      <div className="flex items-start gap-3">
        <span
          className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
          style={{ backgroundColor: 'var(--tone-brand-bg)', color: 'var(--tone-brand-fg)' }}
        >
          {icon}
        </span>

        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold">{title}</p>
          <p className="mt-1 text-xs leading-6" style={{ color: 'var(--text-secondary)' }}>
            {body}
          </p>

          <button
            type="button"
            onClick={onAction}
            className="mt-3 rounded-lg px-3 py-1.5 text-xs font-semibold"
            style={{ backgroundColor: 'var(--tone-brand-fg)', color: 'var(--text-on-brand)' }}
          >
            {actionLabel}
          </button>
        </div>

        <button
          type="button"
          onClick={onDismiss}
          aria-label="بستن"
          className="shrink-0 rounded-md p-1"
          style={{ color: 'var(--text-secondary)' }}
        >
          <X className="h-4 w-4" aria-hidden />
        </button>
      </div>
    </div>
  )
}
