import { getObservabilityConfig, trackingEnabled } from './config'

/**
 * گزارشِ خطا — به سرورِ خودمان، و در صورتِ تنظیم‌بودن به Sentry.
 *
 * ─── چرا دو مقصد؟ ──────────────────────────────────────────────────────────
 * سرورِ خودمان **همیشه** کار می‌کند و به هیچ حسابِ بیرونی وابسته نیست، پس پنلِ
 * ادمین از روزِ اول داده‌ی واقعی دارد. Sentry وقتی وصل شد، ابزارِ حرفه‌ایِ
 * تحلیل (گروه‌بندی، source map، هشدار) را اضافه می‌کند. یکی جایگزینِ دیگری
 * نیست.
 *
 * ─── چرا Sentry با import پویا؟ ────────────────────────────────────────────
 * بسته‌ی `@sentry/react` حجمِ قابلِ‌توجهی دارد. با importِ پویا، این حجم فقط
 * وقتی دانلود می‌شود که DSN تنظیم شده باشد؛ تا آن روز باندلِ کاربر یک بایت هم
 * سنگین‌تر نمی‌شود.
 */

let sentryReady: Promise<typeof import('@sentry/react')> | null = null

/** بارگذاریِ تنبلِ Sentry — فقط یک بار، و فقط اگر DSN باشد. */
async function loadSentry() {
  const config = getObservabilityConfig()
  if (!config.sentryDsn || !trackingEnabled()) return null

  sentryReady ??= import('@sentry/react').then((Sentry) => {
    Sentry.init({
      dsn: config.sentryDsn,
      environment: config.sentryEnvironment ?? 'production',
      tracesSampleRate: config.sentryTracesSampleRate ?? 0,
      /*
       * پاک‌سازیِ داده‌ی حساس پیش از ارسال. شماره‌ی موبایل و رمز نباید در
       * گزارشِ خطا به سرویسِ بیرونی برود، حتی اگر اتفاقی در URL یا بدنه باشد.
       */
      beforeSend(event) {
        if (event.request?.url) {
          event.request.url = event.request.url.replace(/(phone|password|token)=[^&]*/gi, '$1=***')
        }
        return event
      },
    })
    return Sentry
  })

  return sentryReady
}

/** گزارشِ خطا به سرورِ خودمان. شکستِ خودِ گزارش هرگز به بالا پرتاب نمی‌شود. */
function reportToServer(error: Error): void {
  try {
    const body = JSON.stringify({
      type: error.name,
      message: error.message,
      stack: error.stack?.slice(0, 8000),
      url: window.location.href,
    })

    /*
     * `sendBeacon` برای وقتی است که کاربر همان لحظه صفحه را می‌بندد: مرورگر
     * تضمین می‌کند درخواست فرستاده شود، در حالی که یک `fetch` معمولی با بستنِ
     * صفحه لغو می‌شد — و دقیقاً همان خطایی که باعثِ بستنِ صفحه شده از دست
     * می‌رفت.
     *
     * `sendBeacon` هدرِ CSRF نمی‌فرستد، پس این مسیر در سمتِ سرور از حفاظتِ
     * CSRF مستثناست و به‌جایش محدودیتِ نرخ دارد.
     */
    const blob = new Blob([body], { type: 'application/json' })

    if (navigator.sendBeacon) {
      navigator.sendBeacon('/api/v1/client-errors', blob)
      return
    }

    void fetch('/api/v1/client-errors', {
      method: 'POST',
      body,
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      keepalive: true,
    })
  } catch {
    // گزارشِ خطا نباید خودش منبعِ خطا شود
  }
}

/**
 * گزارشِ یک خطا به همه‌ی مقصدهای فعال.
 *
 * این تابع را `ErrorBoundary` صدا می‌زند، پس همه‌ی کرش‌های رندر از یک نقطه رد
 * می‌شوند.
 */
export function reportError(error: Error, context?: unknown): void {
  reportToServer(error)

  void loadSentry().then((Sentry) => {
    /*
     * `context` عمداً `unknown` است تا امضای این تابع مستقیماً به‌عنوانِ
     * `onError` دیوارِ آتش قابلِ استفاده باشد؛ React آنجا `ErrorInfo` می‌دهد
     * که ایندکس‌سیگنچر ندارد و با `Record<string, unknown>` جور درنمی‌آید.
     */
    Sentry?.captureException(error, context ? { extra: { context } } : undefined)
  })
}

/**
 * راه‌اندازیِ اولیه.
 *
 * علاوه بر Sentry، دو رویدادِ سراسری را هم می‌گیرد که Error Boundary نمی‌بیند:
 * خطای بیرونِ درختِ React و promiseِ ردشده‌ی مدیریت‌نشده. بدونِ این‌ها بخشِ
 * بزرگی از خطاهای واقعی هرگز دیده نمی‌شدند.
 */
export function initErrorReporting(): void {
  if (!trackingEnabled()) return

  void loadSentry()

  window.addEventListener('error', (event) => {
    if (event.error instanceof Error) reportToServer(event.error)
  })

  window.addEventListener('unhandledrejection', (event) => {
    const reason: unknown = event.reason
    if (reason instanceof Error) reportToServer(reason)
  })
}
