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

export { trackEvent, trackPageView } from './analytics'
export { reportError } from './errorReporting'
export { getObservabilityConfig } from './config'
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
}
