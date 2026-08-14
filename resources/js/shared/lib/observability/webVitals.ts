import { trackingEnabled } from './config'

/**
 * سنجشِ Core Web Vitals از دستگاهِ کاربرِ واقعی (R38 · آیتم ③).
 *
 * ─── چرا دست‌نویس و نه پکیجِ `web-vitals` ──────────────────────────────────
 * آن پکیج همین کار را می‌کند و حاشیه‌های مرورگری بیشتری پوشش می‌دهد، ولی
 * یک وابستگیِ تازه است و پروژه قیدِ «پکیجِ بی‌دلیل نصب نکن» دارد. آنچه لازم
 * است سه سنجه‌ی اصلی روی مرورگرهای امروزی است و `PerformanceObserver`
 * خودش هر سه را می‌دهد.
 *
 * ─── چرا اصلاً سنجش، وقتی Lighthouse هست ─────────────────────────────────
 * Lighthouse یک بار، روی یک دستگاه، با شبکه‌ی شبیه‌سازی‌شده اندازه می‌گیرد.
 * چیزی که در رتبه‌ی جستجو اثر دارد، **دادهٔ میدانی** است: همان اعدادی که
 * روی گوشیِ ساکنی که با اینترنتِ همراه وارد می‌شود ثبت می‌شوند. این دو
 * معمولاً خیلی با هم فرق دارند.
 */

/** آستانه‌های «خوب» طبقِ تعریفِ خودِ گوگل. */
const GOOD = { LCP: 2500, CLS: 0.1, INP: 200, TTFB: 800, FCP: 1800 } as const

export type VitalName = keyof typeof GOOD

export interface Vital {
  name: VitalName
  value: number
  rating: 'good' | 'needs-improvement' | 'poor'
}

/**
 * رتبه‌بندی طبقِ آستانه‌ی گوگل.
 *
 * مرزِ «بد» دو برابرِ مرزِ «خوب» است — همان قاعده‌ای که خودِ گوگل در
 * گزارشِ تجربه‌ی کاربر به کار می‌برد.
 */
export function rate(name: VitalName, value: number): Vital['rating'] {
  const good = GOOD[name]

  if (value <= good) return 'good'

  return value <= good * 2 ? 'needs-improvement' : 'poor'
}

type Reporter = (vital: Vital) => void

/** ناظرِ امن: نوعِ پشتیبانی‌نشده فقط رد می‌شود، نه اینکه صفحه را بشکند. */
function observe(type: string, callback: (entries: PerformanceEntry[]) => void): void {
  if (typeof PerformanceObserver === 'undefined') return
  if (!PerformanceObserver.supportedEntryTypes?.includes(type)) return

  try {
    const observer = new PerformanceObserver((list) => callback(list.getEntries()))

    /*
     * `buffered: true` اجباری است: بیشترِ این رویدادها **پیش از** اجرای
     * این کد رخ داده‌اند (paint و LCP در همان میلی‌ثانیه‌های اول). بدونش
     * فقط رویدادهای بعدی را می‌گرفتیم و LCP تقریباً همیشه از دست می‌رفت.
     */
    observer.observe({ type, buffered: true })
  } catch {
    // مرورگری که این نوع را نمی‌شناسد نباید باعثِ خطای صفحه شود
  }
}

/**
 * جمع‌آوریِ سنجه‌ها و گزارشِ **یک‌باره** هنگامِ ترکِ صفحه.
 *
 * ⚠️ چرا هنگامِ ترک و نه بی‌درنگ: LCP و CLS تا لحظه‌ی آخر می‌توانند عوض
 * شوند — تصویری که دیر می‌رسد LCP را جابه‌جا می‌کند و هر پرشِ چیدمان به
 * CLS اضافه می‌شود. گزارشِ زودهنگام یعنی ثبتِ عددی که هنوز نهایی نشده.
 */
export function collectWebVitals(report: Reporter): void {
  if (typeof window === 'undefined' || !trackingEnabled()) return

  const vitals = new Map<VitalName, number>()

  const set = (name: VitalName, value: number) => {
    vitals.set(name, value)
  }

  // TTFB و FCP — پایه‌ای‌ترین‌ها و بی‌ربط به تعاملِ کاربر
  const navigation = performance.getEntriesByType('navigation')[0] as
    PerformanceNavigationTiming | undefined

  if (navigation) set('TTFB', Math.round(navigation.responseStart))

  observe('paint', (entries) => {
    for (const entry of entries) {
      if (entry.name === 'first-contentful-paint') set('FCP', Math.round(entry.startTime))
    }
  })

  // LCP: آخرین ورودی معتبر است، نه اولی
  observe('largest-contentful-paint', (entries) => {
    const last = entries[entries.length - 1]

    if (last) set('LCP', Math.round(last.startTime))
  })

  /*
   * CLS: مجموعِ بدترین **پنجره** است، نه مجموعِ کل.
   *
   * ⚠️ تعریفِ گوگل «بزرگ‌ترین دسته‌ی پرش‌ها» است: پرش‌هایی که کمتر از یک
   * ثانیه از هم فاصله دارند و کلِ دسته زیرِ پنج ثانیه است. جمعِ ساده‌ی همه‌ی
   * پرش‌ها عددِ خیلی بدتری می‌دهد و با آنچه Search Console نشان می‌دهد
   * نمی‌خواند.
   */
  let clsValue = 0
  let sessionValue = 0
  let sessionStart = 0
  let sessionLast = 0

  observe('layout-shift', (entries) => {
    for (const entry of entries as (PerformanceEntry & {
      value: number
      hadRecentInput: boolean
    })[]) {
      // پرشی که بلافاصله پس از کنشِ کاربر رخ دهد، تقصیرِ ما نیست
      if (entry.hadRecentInput) continue

      const withinSession =
        sessionValue !== 0 &&
        entry.startTime - sessionLast < 1000 &&
        entry.startTime - sessionStart < 5000

      if (withinSession) {
        sessionValue += entry.value
      } else {
        sessionValue = entry.value
        sessionStart = entry.startTime
      }

      sessionLast = entry.startTime
      clsValue = Math.max(clsValue, sessionValue)
      set('CLS', Number(clsValue.toFixed(4)))
    }
  })

  /*
   * INP: کندترین تعاملِ کاربر.
   *
   * `durationThreshold: 40` پیش‌فرضِ ۱۰۴ را پایین می‌آورد تا تعامل‌های
   * متوسط هم دیده شوند؛ زیرِ ۴۰ میلی‌ثانیه از دیدِ کاربر آنی است و ثبتش
   * فقط نویز می‌سازد.
   */
  let worstInteraction = 0

  if (
    typeof PerformanceObserver !== 'undefined' &&
    PerformanceObserver.supportedEntryTypes?.includes('event')
  ) {
    try {
      const observer = new PerformanceObserver((list) => {
        for (const entry of list.getEntries() as (PerformanceEntry & {
          interactionId?: number
        })[]) {
          // فقط رویدادهایی که واقعاً تعاملِ کاربر بوده‌اند شناسه می‌گیرند
          if (!entry.interactionId) continue

          worstInteraction = Math.max(worstInteraction, Math.round(entry.duration))
          set('INP', worstInteraction)
        }
      })

      observer.observe({
        type: 'event',
        buffered: true,
        durationThreshold: 40,
      } as PerformanceObserverInit)
    } catch {
      // پشتیبانی‌نشده — بقیه‌ی سنجه‌ها سرِ جایشان می‌مانند
    }
  }

  /*
   * ⚠️ `visibilitychange` و نه `beforeunload`.
   *
   * روی موبایل `beforeunload` در بسیاری از حالت‌ها اصلاً شلیک نمی‌شود
   * (تعویضِ اپ، بستنِ تب از نمای چندوظیفه‌ای) — یعنی دقیقاً روی همان
   * دستگاهی که سنجه‌هایش مهم‌تر است، هیچ داده‌ای نمی‌گرفتیم.
   */
  let sent = false

  const flush = () => {
    if (sent || document.visibilityState !== 'hidden') return

    sent = true

    for (const [name, value] of vitals) {
      report({ name, value, rating: rate(name, value) })
    }
  }

  document.addEventListener('visibilitychange', flush)
  window.addEventListener('pagehide', flush)
}
