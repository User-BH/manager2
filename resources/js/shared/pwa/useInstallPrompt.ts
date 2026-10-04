import { useCallback, useEffect, useState } from 'react'
import {
  dismissForever,
  installGuide,
  isDismissed,
  markInstalled,
  markNotInstalled,
  mayOffer,
  runningInstalled,
  type InstallGuide,
} from './installState'

/**
 * دکمه‌ی نصبِ سفارشی (R35) و راهنمای مرورگرهای بی‌رویداد.
 *
 * ─── چرا دکمه‌ی خودمان ─────────────────────────────────────────────────────
 * کروم پیشنهادِ نصب را در منویی پنهان می‌کند که بیشترِ کاربران هرگز باز
 * نمی‌کنند. `beforeinstallprompt` اجازه می‌دهد همان پیشنهاد را نگه داریم و
 * سرِ جای درست خودمان نشان بدهیم.
 *
 * چه وقت چه چیزی نشان داده می‌شود و چرا: `installState.ts` (مرحلهٔ ۸ · فاز ۲).
 */

/** رویدادی که تایپ‌اسکریپت نمی‌شناسد چون هنوز در استاندارد نیست. */
interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

export function useInstallPrompt() {
  const [event, setEvent] = useState<BeforeInstallPromptEvent | null>(null)
  const [installed, setInstalled] = useState(runningInstalled)
  // راهنما رویدادی ندارد که با بستن پاک شود؛ این رندرِ دوباره را می‌سازد
  const [closed, setClosed] = useState(false)

  /*
   * ⚠️ مجاز بودنِ راهنما **یک بار، هنگامِ سوارشدن** سنجیده می‌شود: کارت پس از
   * نمایش «دیده شد» را ثبت می‌کند و اگر این‌جا هر رندر دوباره سنجیده می‌شد،
   * کارت با نشان‌دادنِ خودش ناپدید می‌شد.
   */
  const [guide] = useState<InstallGuide | null>(() => {
    const kind = installGuide()
    return kind && mayOffer(kind) ? kind : null
  })

  useEffect(() => {
    // داخلِ اپ: یادِ این انباره بماند که اپ این‌جا هست (فایرفاکسِ اندروید از آن سود می‌برد)
    if (runningInstalled()) markInstalled()

    const onPrompt = (native: Event) => {
      /*
       * جلوی نوارِ پیش‌فرضِ مرورگر گرفته می‌شود و خودِ رویداد نگه داشته
       * می‌شود؛ بعداً فقط با ژستِ کاربر می‌توان `prompt()` را صدا زد. اگر
       * همین تازگی پیشنهاد دیده شده، نگه داشته نمی‌شود: مرورگر هم نوارِ
       * خودش را نشان نمی‌دهد و نصب از نشانِ نوارِ آدرس همچنان در دسترس است.
       */
      native.preventDefault()
      // مرورگر خودش می‌گوید نصب نیست — هر نشانه‌ی قدیمیِ «نصب‌شده» کهنه است
      markNotInstalled()
      if (mayOffer('native')) setEvent(native as BeforeInstallPromptEvent)
    }

    const onInstalled = () => {
      markInstalled()
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

  const dismissed = closed || isDismissed()

  const install = useCallback(async () => {
    if (!event) return 'unavailable' as const

    await event.prompt()
    const { outcome } = await event.userChoice

    // رویداد یک‌بارمصرف است؛ نگه‌داشتنش بعد از استفاده فقط دکمه‌ی مرده می‌سازد
    setEvent(null)

    return outcome
  }, [event])

  const dismiss = useCallback(() => {
    dismissForever()
    setClosed(true)
    setEvent(null)
  }, [])

  return {
    /** فقط وقتی درست است که مرورگر واقعاً پیشنهاد داده و کاربر ردش نکرده. */
    canInstall: event !== null && !installed && !dismissed,
    /** مرورگرِ بی‌رویداد: راهنمای منوی خودش، نه دکمه‌ی ساختگیِ «نصب». */
    guide: event === null && !installed && !dismissed ? guide : null,
    installed,
    install,
    dismiss,
  }
}
