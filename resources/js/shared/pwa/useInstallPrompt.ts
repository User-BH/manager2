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

export function useInstallPrompt() {
  const [event, setEvent] = useState<BeforeInstallPromptEvent | null>(null)
  const [installed, setInstalled] = useState(isInstalled)

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

  const dismissed =
    typeof localStorage !== 'undefined' && localStorage.getItem(DISMISSED_KEY) === '1'

  const install = useCallback(async () => {
    if (!event) return 'unavailable' as const

    await event.prompt()
    const { outcome } = await event.userChoice

    // رویداد یک‌بارمصرف است؛ نگه‌داشتنش بعد از استفاده فقط دکمه‌ی مرده می‌سازد
    setEvent(null)

    return outcome
  }, [event])

  const dismiss = useCallback(() => {
    localStorage.setItem(DISMISSED_KEY, '1')
    setEvent(null)
  }, [])

  return {
    /** فقط وقتی درست است که مرورگر واقعاً پیشنهاد داده و کاربر ردش نکرده. */
    canInstall: event !== null && !installed && !dismissed,
    installed,
    install,
    dismiss,
  }
}
