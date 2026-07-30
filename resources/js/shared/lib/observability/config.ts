/**
 * خواندنِ شناسه‌های پایش که سرور در `<head>` گذاشته است.
 *
 * منبعِ این مقادیر `App\Support\Observability::clientConfig()` است و اولویتش
 * «پنلِ ادمین ⟶ .env» است. یعنی فرانت هیچ شناسه‌ای را هاردکد نمی‌کند و تغییرِ
 * حسابِ تحلیلی نه به تغییرِ کد نیاز دارد و نه به بیلدِ دوباره.
 *
 * اگر تگ نباشد (یعنی هیچ سرویسی تنظیم نشده)، همه‌چیز خاموش می‌ماند و حتی یک
 * بایت هم دانلود نمی‌شود.
 */

export interface ObservabilityConfig {
  sentryDsn?: string
  sentryEnvironment?: string
  sentryTracesSampleRate?: number
  ga4MeasurementId?: string
  gtmContainerId?: string
  clarityProjectId?: string
}

let cached: ObservabilityConfig | null = null

export function getObservabilityConfig(): ObservabilityConfig {
  if (cached) return cached

  const element = document.getElementById('observability-config')
  if (!element?.textContent) {
    cached = {}
    return cached
  }

  try {
    cached = JSON.parse(element.textContent) as ObservabilityConfig
  } catch {
    /*
     * پیکربندیِ خراب نباید برنامه را بخواباند. تحلیل یک قابلیتِ جانبی است؛
     * اگر نیامد، کاربر نباید حتی متوجه شود.
     */
    cached = {}
  }

  return cached
}

/**
 * آیا باید ردیابی کنیم؟
 *
 * در حالتِ توسعه عمداً خاموش است: وگرنه هر بار که برنامه‌نویس صفحه را باز
 * می‌کند، آمارِ واقعیِ سایت آلوده می‌شود و نمودارهای پنل بی‌معنا می‌شوند.
 */
export function trackingEnabled(): boolean {
  return import.meta.env.PROD
}
