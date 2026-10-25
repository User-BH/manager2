import { Suspense, lazy, useState, useSyncExternalStore } from 'react'
import { CheckCircle2, Download, Loader2 } from 'lucide-react'
import { installGuide, runningInstalled } from './installState'
import { installSnapshot, promptInstall, subscribeInstall } from './installEvent'
import type { InstallHelpKind } from './InstallHelp'

const InstallHelp = lazy(() => import('./InstallHelp'))

/**
 * دکمه‌ی نصبِ اپلیکیشن در Hero ِ صفحه‌ی اصلی (مرحلهٔ ۱۳ · فاز ۲؛ ایرادات ۱۱، بخشِ ۱).
 *
 * رفتار از قابلیتِ **واقعیِ** همین مرورگر می‌آید، نه از ظاهرِ دکمه:
 *
 *   - **کرومیوم با رویدادِ نصب** (`beforeinstallprompt`، انباره‌ی مشترکِ `installEvent.ts`): کلیک همان پنجره‌ی نصبِ
 *     مرورگر را باز می‌کند. «نصب» ِ کاربر فقط «در انتظارِ مرورگر» است؛ «نصب شد» فقط با `appinstalled` ِ خودِ مرورگر.
 *     انصراف یعنی همان دکمه با جمله‌ی «نصب انجام نشد» — هیچ ادعای نصبی.
 *   - **iOS، سافاریِ مک، فایرفاکسِ اندروید** (رویداد ندارند، نصب از منوی خودشان): کلیک راهنمای مشروطِ همان مرورگر را باز
 *     می‌کند.
 *   - **بی رویداد و بی راهنما** (کرومیوم پیش از پیشنهاد، فایرفاکسِ دسکتاپ، مرورگرِ درون‌برنامه‌ای): کلیک می‌گوید چرا نصبِ
 *     مستقیم نیست و راهِ دیگر چیست — نه پنجره‌ی ساختگی.
 *   - **داخلِ اپِ نصب‌شده** (`display-mode`/`navigator.standalone`): دکمه نیست.
 *
 * مستقل از کارتِ شناور: «نه، ممنون» ِ آن کارت و فاصله‌ی تکرارش این دکمه را پنهان نمی‌کند — این‌جا خودِ کاربر درخواست
 * می‌کند. هر دو یک رویداد را دو بار مصرف نمی‌کنند (همان انباره). رفتارِ Service Worker و به‌روزرسانی دست نمی‌خورد: این
 * دکمه SW ثبت نمی‌کند.
 */
export function HeroInstallButton() {
  const { event, installed } = useSyncExternalStore(subscribeInstall, installSnapshot)
  // یک بار، هنگامِ سوارشدن: داخلِ اپ اصلاً دکمه‌ای نیست
  const [inApp] = useState(runningInstalled)
  const [help, setHelp] = useState<InstallHelpKind | null>(null)
  const [waiting, setWaiting] = useState(false)

  if (inApp) return null

  async function onClick() {
    if (!event) {
      setHelp((open) => (open ? null : (installGuide() ?? 'unavailable')))
      return
    }

    setHelp(null)
    const outcome = await promptInstall()
    if (outcome === 'accepted') setWaiting(true)
    else setHelp('dismissed')
  }

  return (
    <>
      <button
        type="button"
        onClick={() => void onClick()}
        disabled={installed}
        aria-expanded={help !== null}
        aria-controls={help ? 'hero-install-help' : undefined}
        data-testid="hero-install"
        className="flex items-center gap-2 rounded-2xl px-5 py-3.5 text-sm font-semibold transition-colors hover:bg-(--surface-sunken) disabled:hover:bg-transparent"
        style={{ color: installed ? 'var(--state-success)' : 'var(--color-brand-600)' }}
      >
        {installed ? (
          <CheckCircle2 size={18} />
        ) : waiting ? (
          <Loader2 size={18} className="animate-spin" />
        ) : (
          <Download size={18} />
        )}
        <span aria-live="polite">
          {installed ? 'اپلیکیشن نصب شد' : waiting ? 'در انتظارِ مرورگر…' : 'نصب اپلیکیشن'}
        </span>
      </button>

      {help && (
        <Suspense fallback={null}>
          <InstallHelp kind={help} onClose={() => setHelp(null)} />
        </Suspense>
      )}
    </>
  )
}
