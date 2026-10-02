import { useEffect, useRef, useState } from 'react'
import { RefreshCw, X } from 'lucide-react'

import { useFeatures } from '@/shared/hooks'
import { useAppBuildStore } from './appBuild'
import { registerServiceWorker, unregisterServiceWorker } from './registerServiceWorker'
import { InstallPrompt } from './InstallPrompt'

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
  const { isEnabled } = useFeatures()
  const pwaEnabled = isEnabled('pwa')

  /*
   * نسخه‌ای که سرور اعلام کرده و با نسخهٔ این صفحه فرق دارد (R61).
   *
   * ⚠️ این با «نسخهٔ تازه‌ی SW آماده است» یکی نیست و مقدم بر آن است:
   * آن یکی می‌گوید «می‌توانی به‌روز شوی»، این یکی می‌گوید «از وقتی این تب
   * باز شده استقرار انجام شده» — یعنی همین حالا ممکن است چیزی درست کار
   * نکند.
   */
  const staleBuild = useAppBuildStore((state) => state.serverBuild)

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
    /*
     * 🔴 کلیدِ خاموشی مقدم بر ثبت است (R61).
     *
     * اگر پرچمِ `pwa` خاموش شده باشد یعنی نسخه‌ای از service worker خراب
     * منتشر شده و باید از دستگاه‌ها برداشته شود. ثبتِ دوباره در آن حالت
     * دقیقاً همان چیزی است که نباید بشود.
     *
     * ⚠️ `pwaEnabled` تا نیامدنِ پاسخ `true` است (پیش‌فرضِ `useFeatures`)،
     * پس قطعیِ لحظه‌ایِ شبکه SW را از دستگاهِ کسی پاک نمی‌کند.
     */
    if (!pwaEnabled) {
      void unregisterServiceWorker()

      return
    }

    const registered = registerServiceWorker()

    if (!registered) return

    handle.current = registered
    registered.onUpdateReady(() => setUpdateReady(true))
  }, [pwaEnabled])

  /*
   * 🔴 ناهماهنگیِ نسخه مقدم بر همه است.
   *
   * ⚠️ و باز هم **تحمیل نمی‌شود**: کاربری که وسطِ پرکردنِ فرمِ هزینه یا
   * پرداخت است نباید کارش را از دست بدهد. همان استدلالی که در R35 جلوی
   * `skipWaiting`ِ خودکار را گرفت.
   */
  if (staleBuild) {
    return (
      <Banner
        icon={<RefreshCw className="h-4 w-4" aria-hidden />}
        title="نسخه‌ی تازه‌ای منتشر شده"
        body="این صفحه با نسخه‌ی قبلی باز مانده. تا بارگذاری دوباره، ممکن است بخش‌هایی درست کار نکنند."
        actionLabel="بارگذاری دوباره"
        onAction={() => window.location.reload()}
        onDismiss={() => useAppBuildStore.setState({ serverBuild: null })}
      />
    )
  }

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

  /*
   * پیشنهادِ نصب همان کامپوننتِ صفحه‌های عمومی است (مرحلهٔ ۷ · فاز ۵): راهنمای
   * iOS و انیمیشنِ خروج به چپ را از همان‌جا می‌گیرد و خودش تصمیم می‌گیرد
   * نشان داده شود یا نه.
   */
  return <InstallPrompt placement="dashboard" />
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
