/**
 * نمای عمومیِ لایه‌ی پایش و تحلیل.
 *
 * بقیه‌ی برنامه فقط همین فایل را می‌شناسد؛ اینکه پشتِ آن GA4 است یا GTM یا
 * Sentry، جزئیاتِ درونی است. اگر روزی سرویسی عوض شود، فقط همین پوشه تغییر
 * می‌کند.
 *
 * @example
 * initObservability()                       // یک بار، در نقطه‌ی ورود
 * trackPageView('/dashboard')               // با هر تغییرِ مسیر
 * trackEvent('bill_paid', { amount: 120 })  // رویدادِ تجاری
 * reportError(error)                        // در Error Boundary
 */

import { initAnalytics } from './analytics'
import { initErrorReporting } from './errorReporting'
import { collectWebVitals, type Vital } from './webVitals'

export { trackEvent, trackPageView } from './analytics'
export { reportError } from './errorReporting'
export { getObservabilityConfig } from './config'
export { rate as rateVital } from './webVitals'
export type { Vital, VitalName } from './webVitals'
export type { ObservabilityConfig } from './config'

/**
 * راه‌اندازیِ همه‌ی سرویس‌های تنظیم‌شده.
 *
 * اگر هیچ شناسه‌ای تنظیم نشده باشد، این تابع عملاً هیچ کاری نمی‌کند و هیچ
 * درخواستِ شبکه‌ای نمی‌سازد.
 */
export function initObservability(): void {
  initAnalytics()
  initErrorReporting()
  initWebVitals()
}

/** دستهٔ سنجه‌ها را در یک درخواست می‌فرستد. */
function initWebVitals(): void {
  const batch: Vital[] = []

  collectWebVitals((vital) => {
    batch.push(vital)

    /*
     * ⚠️ `queueMicrotask` نه `setTimeout`.
     *
     * جمع‌آورنده همه‌ی سنجه‌ها را در یک حلقه‌ی همگام گزارش می‌کند و ما
     * می‌خواهیم **یک** درخواست برای کلِ دسته برود. میکروتسک بلافاصله پس از
     * پایانِ همان حلقه اجرا می‌شود؛ `setTimeout` ممکن است در صفحه‌ای که
     * دارد بسته می‌شود اصلاً اجرا نشود.
     */
    if (batch.length === 1) queueMicrotask(() => send(batch.splice(0)))
  })
}

function send(metrics: Vital[]): void {
  if (metrics.length === 0) return

  const body = JSON.stringify({
    metrics,
    // بدونِ کوئری: `?source=pwa` و `?utm_*` همان صفحه‌اند
    path: window.location.pathname,
    device: deviceClass(),
  })

  /*
   * `sendBeacon` چون این کد هنگامِ **ترکِ صفحه** اجرا می‌شود و یک `fetch`
   * معمولی همان‌جا لغو می‌شد — یعنی دقیقاً داده‌ای که برایش ساخته شده از
   * دست می‌رفت.
   */
  try {
    if (navigator.sendBeacon) {
      navigator.sendBeacon('/api/v1/web-vitals', new Blob([body], { type: 'application/json' }))

      return
    }

    void fetch('/api/v1/web-vitals', {
      method: 'POST',
      body,
      headers: { 'Content-Type': 'application/json' },
      keepalive: true,
    })
  } catch {
    // سنجشِ کارایی نباید خودش منبعِ خطا شود
  }
}

/**
 * دسته‌بندیِ دستگاه.
 *
 * ⚠️ تفکیک لازم است چون آستانه‌ها یکی است ولی سخت‌افزار نه؛ اگر همه را با
 * هم ببینیم، افتِ موبایل زیرِ عددِ خوبِ دسکتاپ پنهان می‌شود.
 */
function deviceClass(): 'mobile' | 'tablet' | 'desktop' {
  const coarse = window.matchMedia?.('(pointer: coarse)').matches ?? false

  if (!coarse) return 'desktop'

  return Math.min(window.screen.width, window.screen.height) >= 600 ? 'tablet' : 'mobile'
}
