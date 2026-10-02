import { Suspense, lazy, useEffect, useState } from 'react'
import { AnimatePresence } from 'framer-motion'
import { useInstallPrompt } from './useInstallPrompt'

const InstallPromptCard = lazy(() => import('./InstallPromptCard'))

/**
 * پیشنهادِ نصبِ اپ — هم صفحه‌های عمومی، هم داشبورد (مرحلهٔ ۷ · فاز ۵).
 *
 * ─── چه چیزی عوض شد ───────────────────────────────────────────────────────
 * سازوکارش از R35 بود ولی فقط در داشبورد؛ بازدیدکننده‌ی صفحه‌ی اصلی که هنوز
 * وارد نشده بود هرگز آن را نمی‌دید. حالا:
 *
 *   • **فقط رویدادِ واقعیِ مرورگر** (`beforeinstallprompt`) آن را نشان می‌دهد؛
 *     جایی که مرورگر نصب را پیشنهاد نکرده، کارتِ ساختگی نمی‌آید. معیارِ
 *     کروم دیگر service worker نمی‌خواهد؛ manifest (که در همه‌ی صفحه‌های
 *     عمومی هست) و کمی تعامل با صفحه کافی است — پس SW فقط برای این روی
 *     صفحه‌های عمومی ثبت نمی‌شود.
 *   • **iOS** رویدادی ندارد و نصب فقط از «اشتراک‌گذاری ← افزودن به صفحه‌ی
 *     اصلی» است؛ آنجا راهنمای همان راه می‌آید، نه دکمه‌ای که کاری نمی‌کند.
 *   • **بستن** با انیمیشن به چپ می‌رود و بعد از DOM برداشته می‌شود
 *     (`AnimatePresence`)؛ بستن به خاطر سپرده می‌شود و با هر بارگذاری
 *     دوباره تحمیل نمی‌شود — همان کلیدِ داشبورد، پس یک بار بستن همه‌جا بس است.
 *   • اگر برنامه نصب‌شده باز شده باشد هیچ‌چیز نشان داده نمی‌شود.
 *
 * `placement`:
 *   - `public` — پایین‌راست، **کنارِ** ستونِ دکمه‌های شناور (دستیارِ پشتیبانی
 *     و «بالا»)، نه رویشان. روی موبایل از لبه‌ی چپ تا کنارِ همان ستون.
 *   - `public-bare` — صفحه‌ای بی‌دکمه‌ی شناور (بلاگ): خودِ گوشه.
 *   - `dashboard` — همان جای پیام‌های PWAِ داشبورد.
 */
export function InstallPrompt({
  placement = 'public',
}: {
  placement?: 'public' | 'public-bare' | 'dashboard'
}) {
  const { canInstall, showIosGuide, install, dismiss } = useInstallPrompt()
  const available = canInstall || showIosGuide

  /*
   * مکثِ کوتاه پیش از آمدن: کارتی که هم‌زمان با خودِ صفحه بالا بپرد، اولین
   * چیزی است که چشم می‌بیند و جای محتوا را می‌گیرد. رویدادِ کروم خودش پس از
   * تعامل می‌رسد؛ راهنمای iOS بی‌این مکث همان لحظه‌ی باز شدن می‌آمد.
   */
  const [ready, setReady] = useState(false)

  useEffect(() => {
    if (!available) return

    const timer = window.setTimeout(() => setReady(true), showIosGuide ? 4000 : 1500)

    return () => window.clearTimeout(timer)
  }, [available, showIosGuide])

  const visible = available && ready

  return (
    <AnimatePresence>
      {visible && (
        // ⚠️ کلید روی Suspense است: فرزندِ مستقیمِ AnimatePresence همین است
        <Suspense key="install-prompt" fallback={null}>
          <InstallPromptCard
            placement={placement}
            ios={showIosGuide}
            onInstall={() => void install()}
            onDismiss={dismiss}
          />
        </Suspense>
      )}
    </AnimatePresence>
  )
}
