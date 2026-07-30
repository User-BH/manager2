import { getObservabilityConfig, trackingEnabled } from './config'

/**
 * GA4، Google Tag Manager و Microsoft Clarity.
 *
 * ─── قاعده‌ی مشترکِ هر سه ───────────────────────────────────────────────────
 * اسکریپت فقط وقتی به صفحه اضافه می‌شود که شناسه‌اش تنظیم شده باشد. تا آن
 * لحظه **هیچ درخواستی به دامنه‌ی گوگل یا مایکروسافت نمی‌رود** — نه برای
 * کارایی هزینه دارد و نه از نظرِ حریمِ خصوصی چیزی می‌فرستد.
 *
 * ─── چرا این‌ها با `<script>` تزریق می‌شوند و نه با npm؟ ────────────────────
 * هر سه سرویس اسکریپتشان را از CDNِ خودشان سرو می‌کنند و نسخه‌ی npm ندارند
 * (یا نسخه‌ی npmشان فقط یک لفافِ همین تزریق است). پس تزریقِ مستقیم هم سبک‌تر
 * است و هم همیشه آخرین نسخه را می‌گیرد.
 */

/** بارگذاریِ یک‌بارِ اسکریپتِ بیرونی؛ تکرارِ صدا زدن اثری ندارد. */
const loaded = new Set<string>()

function injectScript(src: string, attributes: Record<string, string> = {}): void {
  if (loaded.has(src)) return
  loaded.add(src)

  const script = document.createElement('script')
  script.src = src
  script.async = true
  for (const [key, value] of Object.entries(attributes)) script.setAttribute(key, value)
  document.head.appendChild(script)
}

/* ── GA4 ─────────────────────────────────────────────────────────────────── */

declare global {
  interface Window {
    dataLayer?: unknown[]
    gtag?: (...args: unknown[]) => void
    clarity?: (...args: unknown[]) => void
  }
}

function initGa4(measurementId: string): void {
  window.dataLayer = window.dataLayer ?? []
  window.gtag = function gtag(...args: unknown[]) {
    window.dataLayer?.push(args)
  }

  window.gtag('js', new Date())
  /*
   * `send_page_view: false` عمدی است. این یک SPA است و GA4 خودش فقط بارگذاریِ
   * اولِ سند را می‌بیند؛ ناوبریِ داخلی را نمی‌فهمد. پس بازدیدها را خودمان در
   * `trackPageView` می‌فرستیم تا نه از دست بروند و نه دوبار شمرده شوند.
   */
  window.gtag('config', measurementId, { send_page_view: false })

  injectScript(`https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(measurementId)}`)
}

/* ── Google Tag Manager ──────────────────────────────────────────────────── */

function initGtm(containerId: string): void {
  window.dataLayer = window.dataLayer ?? []
  window.dataLayer.push({ 'gtm.start': Date.now(), event: 'gtm.js' })

  injectScript(`https://www.googletagmanager.com/gtm.js?id=${encodeURIComponent(containerId)}`)
}

/* ── Microsoft Clarity ───────────────────────────────────────────────────── */

function initClarity(projectId: string): void {
  if (window.clarity) return

  // صف تا وقتی اسکریپتِ اصلی برسد؛ الگوی رسمیِ خودِ Clarity
  const queue: unknown[][] = []
  window.clarity = function clarity(...args: unknown[]) {
    queue.push(args)
  }

  injectScript(`https://www.clarity.ms/tag/${encodeURIComponent(projectId)}`)
}

/* ── نمای عمومی ──────────────────────────────────────────────────────────── */

/** راه‌اندازیِ هر سرویسی که شناسه دارد. صدا زدنِ دوباره بی‌اثر است. */
export function initAnalytics(): void {
  if (!trackingEnabled()) return

  const config = getObservabilityConfig()

  if (config.ga4MeasurementId) initGa4(config.ga4MeasurementId)
  if (config.gtmContainerId) initGtm(config.gtmContainerId)
  if (config.clarityProjectId) initClarity(config.clarityProjectId)
}

/**
 * ثبتِ بازدیدِ صفحه.
 *
 * در SPA باید با هر تغییرِ مسیر دستی صدا زده شود؛ خودِ GA4 ناوبریِ داخلی را
 * نمی‌بیند.
 */
export function trackPageView(path: string, title?: string): void {
  if (!trackingEnabled()) return

  const config = getObservabilityConfig()

  if (config.ga4MeasurementId && window.gtag) {
    window.gtag('event', 'page_view', {
      page_path: path,
      page_title: title ?? document.title,
      page_location: window.location.href,
    })
  }

  // GTM با رویدادِ سفارشی، تا تگ‌های داخلش بتوانند به آن واکنش نشان دهند
  if (config.gtmContainerId) {
    window.dataLayer?.push({ event: 'spa_page_view', page_path: path })
  }
}

/**
 * رویدادِ دلخواه (مثل «قبض پرداخت شد»).
 *
 * عمداً هیچ داده‌ی هویتی نمی‌فرستد: نام، شماره‌ی موبایل و شناسه‌ی کاربر نباید
 * به سرویسِ تحلیلیِ بیرونی برود.
 */
export function trackEvent(name: string, params: Record<string, string | number> = {}): void {
  if (!trackingEnabled()) return

  const config = getObservabilityConfig()

  if (config.ga4MeasurementId && window.gtag) window.gtag('event', name, params)
  if (config.gtmContainerId) window.dataLayer?.push({ event: name, ...params })
}
