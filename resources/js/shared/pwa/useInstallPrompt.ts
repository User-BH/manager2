import { useCallback, useEffect, useState } from 'react'

/**
 * دکمه‌ی نصبِ سفارشی (R35).
 *
 * ─── چرا دکمه‌ی خودمان ─────────────────────────────────────────────────────
 * کروم پیشنهادِ نصب را در منویی پنهان می‌کند که بیشترِ کاربران هرگز باز
 * نمی‌کنند. `beforeinstallprompt` اجازه می‌دهد همان پیشنهاد را نگه داریم و
 * سرِ جای درست خودمان نشان بدهیم.
 */

/** رویدادی که تایپ‌اسکریپت نمی‌شناسد چون هنوز در استاندارد نیست. */
interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

/** کلیدِ یادسپاریِ «نه، ممنون» تا هر بار دوباره نپرسیم. */
const DISMISSED_KEY = 'sakena:install-dismissed'

/**
 * آیا برنامه همین حالا نصب‌شده اجرا می‌شود؟
 *
 * ⚠️ دو راهِ تشخیص لازم است: اندروید/دسکتاپ `display-mode` را درست
 * می‌گویند، ولی سافاریِ iOS آن را برای برنامه‌ی نصب‌شده هم `browser`
 * گزارش می‌کند و به‌جایش `navigator.standalone` دارد. با یکی از این دو،
 * روی iOS دکمه‌ی نصب به کاربری نشان داده می‌شد که از قبل نصبش کرده.
 */
export function isInstalled(): boolean {
  if (typeof window === 'undefined') return false

  const standalone = window.matchMedia?.('(display-mode: standalone)').matches ?? false
  const iosStandalone = (window.navigator as { standalone?: boolean }).standalone === true

  return standalone || iosStandalone
}

/**
 * آیا این مرورگرِ iOS می‌تواند ساکنا را از «اشتراک‌گذاری ← افزودن به صفحه‌ی
 * اصلی» نصب کند؟ (مرحلهٔ ۷ · فاز ۵)
 *
 * iOS رویدادِ `beforeinstallprompt` ندارد؛ نصب فقط از منوی اشتراک‌گذاری است،
 * پس آنجا به‌جای دکمه‌ی نصب **راهنما** نشان می‌دهیم.
 *
 * ⚠️ نشانه، خودِ `navigator.standalone` است، نه رشته‌ی User-Agent به‌تنهایی:
 * این ویژگی فقط در WebKitی تعریف می‌شود که «افزودن به صفحه‌ی اصلی» دارد و
 * بیرون از حالتِ نصب‌شده `false` است. مرورگرهای درون‌برنامه‌ای (اینستاگرام،
 * تلگرام) آن را ندارند — به کاربرشان راهنمایی نمی‌دهیم که در آنجا کار نمی‌کند.
 * آی‌پدِ امروزی خودش را «Macintosh» معرفی می‌کند؛ لمسی‌بودن جدایش می‌کند.
 */
export function canAddToHomeScreen(): boolean {
  if (typeof window === 'undefined') return false

  const nav = window.navigator as Navigator & { standalone?: boolean }
  const ua = nav.userAgent
  const iOS = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && nav.maxTouchPoints > 1)

  return iOS && nav.standalone === false
}

/** ⚠️ `localStorage` در حالتِ خصوصی یا با کوکیِ مسدود پرتاب می‌کند. */
function readDismissed(): boolean {
  try {
    return typeof localStorage !== 'undefined' && localStorage.getItem(DISMISSED_KEY) === '1'
  } catch {
    return false
  }
}

export function useInstallPrompt() {
  const [event, setEvent] = useState<BeforeInstallPromptEvent | null>(null)
  const [installed, setInstalled] = useState(isInstalled)
  // راهنمای iOS رویدادی ندارد که با بستن پاک شود؛ این رندرِ دوباره را می‌سازد
  const [closed, setClosed] = useState(false)

  useEffect(() => {
    const onPrompt = (native: Event) => {
      /*
       * جلوی نوارِ پیش‌فرضِ مرورگر گرفته می‌شود و خودِ رویداد نگه داشته
       * می‌شود؛ بعداً فقط با ژستِ کاربر می‌توان `prompt()` را صدا زد.
       */
      native.preventDefault()
      setEvent(native as BeforeInstallPromptEvent)
    }

    const onInstalled = () => {
      setInstalled(true)
      setEvent(null)
    }

    window.addEventListener('beforeinstallprompt', onPrompt)
    window.addEventListener('appinstalled', onInstalled)

    return () => {
      window.removeEventListener('beforeinstallprompt', onPrompt)
      window.removeEventListener('appinstalled', onInstalled)
    }
  }, [])

  const dismissed = closed || readDismissed()

  const install = useCallback(async () => {
    if (!event) return 'unavailable' as const

    await event.prompt()
    const { outcome } = await event.userChoice

    // رویداد یک‌بارمصرف است؛ نگه‌داشتنش بعد از استفاده فقط دکمه‌ی مرده می‌سازد
    setEvent(null)

    return outcome
  }, [event])

  const dismiss = useCallback(() => {
    try {
      localStorage.setItem(DISMISSED_KEY, '1')
    } catch {
      // حالتِ خصوصیِ سافاری: فقط برای همین صفحه بسته می‌ماند
    }
    setClosed(true)
    setEvent(null)
  }, [])

  return {
    /** فقط وقتی درست است که مرورگر واقعاً پیشنهاد داده و کاربر ردش نکرده. */
    canInstall: event !== null && !installed && !dismissed,
    /** iOS: نصب فقط از منوی اشتراک‌گذاری؛ راهنما به‌جای دکمه (فاز ۵). */
    showIosGuide: event === null && !installed && !dismissed && canAddToHomeScreen(),
    installed,
    install,
    dismiss,
  }
}
