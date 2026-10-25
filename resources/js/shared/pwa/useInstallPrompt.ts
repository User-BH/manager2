import { useCallback, useState, useSyncExternalStore } from 'react'
import {
  dismissForever,
  installGuide,
  isDismissed,
  mayOffer,
  type InstallGuide,
} from './installState'
import { installSnapshot, promptInstall, subscribeInstall } from './installEvent'

/**
 * دکمه‌ی نصبِ سفارشی (R35) و راهنمای مرورگرهای بی‌رویداد — برای کارتِ شناورِ پیشنهادِ نصب.
 *
 * ─── چرا دکمه‌ی خودمان ─────────────────────────────────────────────────────
 * کروم پیشنهادِ نصب را در منویی پنهان می‌کند که بیشترِ کاربران هرگز باز
 * نمی‌کنند. `beforeinstallprompt` اجازه می‌دهد همان پیشنهاد را نگه داریم و
 * سرِ جای درست خودمان نشان بدهیم.
 *
 * چه وقت چه چیزی نشان داده می‌شود و چرا: `installState.ts` (مرحلهٔ ۸ · فاز ۲). خودِ رویداد از ۱۳/۲ در یک انباره‌ی
 * مشترک است (`installEvent.ts`) تا کارت و دکمه‌ی Hero یک رویداد را دو بار مصرف نکنند.
 */
export function useInstallPrompt() {
  const { event, offerCard, installed } = useSyncExternalStore(subscribeInstall, installSnapshot)
  // بستنِ کارت فقط کارت را می‌بندد؛ رویداد برای دکمه‌ی Hero می‌ماند
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

  const dismissed = closed || isDismissed()

  const install = useCallback(() => promptInstall(), [])

  const dismiss = useCallback(() => {
    dismissForever()
    setClosed(true)
  }, [])

  return {
    /** فقط وقتی درست است که مرورگر واقعاً پیشنهاد داده و کاربر ردش نکرده. */
    canInstall: event !== null && offerCard && !installed && !dismissed,
    /*
     * مرورگرِ بی‌رویداد: راهنمای منوی خودش، نه دکمه‌ی ساختگیِ «نصب». نوعِ راهنما فقط برای iOS، سافاریِ مک و فایرفاکسِ
     * اندروید است و این‌ها `beforeinstallprompt` ندارند؛ پس شرطِ «رویداد نیامده» این‌جا لازم نیست (و اگر هر دو بودند،
     * `InstallPrompt` دکمه‌ی واقعی را مقدم می‌گیرد).
     */
    guide: !installed && !dismissed ? guide : null,
    installed,
    install,
    dismiss,
  }
}
