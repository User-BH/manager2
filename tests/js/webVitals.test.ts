import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { collectWebVitals, rate, type Vital } from '@/shared/lib/observability/webVitals'

/*
 * ⚠️ `trackingEnabled()` همان `import.meta.env.PROD` است.
 *
 * یعنی در محیطِ تست همیشه `false` است و جمع‌آورنده زودهنگام برمی‌گردد —
 * که در اولین اجرا باعث شد پنج تست با «چیزی گزارش نشد» بیفتند. این رفتار
 * برای تولید **درست** است (تلمتریِ محیطِ توسعه فقط نویز است)، پس به‌جای
 * عوض‌کردنِ کد، همان تصمیم اینجا بدل گرفته می‌شود.
 */
vi.mock('@/shared/lib/observability/config', () => ({
  trackingEnabled: () => true,
  getObservabilityConfig: () => ({}),
}))

/**
 * سنجشِ Core Web Vitals (R38).
 *
 * ─── چرا این تست‌ها لازم شدند ──────────────────────────────────────────────
 * ⚠️ تلاش کردم این سنجه‌ها را در مرورگرِ واقعی ببینم و نشد: پنلِ مرورگر در
 * این نشست پنهان است، `document.hidden === true` می‌شود و مرورگر برای
 * صفحه‌ای که رندر نمی‌شود **هیچ** ورودیِ `paint`/`largest-contentful-paint`/
 * `layout-shift` تولید نمی‌کند. اندازه‌گیری نشان داد هر سه صفر بودند.
 *
 * پس منطق اینجا با ناظرِ کنترل‌شده اثبات می‌شود، نه با بارگذاریِ واقعی.
 */

type Handler = (entries: PerformanceEntry[]) => void

/** ناظرِ ساختگی که می‌گذارد خودمان ورودی تزریق کنیم. */
function installObserver() {
  const handlers = new Map<string, Handler>()

  class Fake {
    constructor(private callback: PerformanceObserverCallback) {}

    observe(options: { type: string }) {
      handlers.set(options.type, (entries) =>
        this.callback({ getEntries: () => entries } as PerformanceObserverEntryList, this),
      )
    }

    disconnect() {}
    takeRecords() {
      return []
    }

    static supportedEntryTypes = ['paint', 'largest-contentful-paint', 'layout-shift', 'event']
  }

  vi.stubGlobal('PerformanceObserver', Fake)

  return {
    emit(type: string, entries: Array<Partial<PerformanceEntry> | ShiftEntry>) {
      handlers.get(type)?.(entries as PerformanceEntry[])
    },
  }
}

/** سنجه‌ی گزارش‌شده با نامِ داده‌شده. */
function reported(report: ReturnType<typeof vi.fn>, name: Vital['name']): Vital | undefined {
  return (report.mock.calls as [Vital][])
    .map(([vital]) => vital)
    .find((vital) => vital.name === name)
}

/** ورودیِ ساختگیِ پرشِ چیدمان — `PerformanceEntry` این فیلدها را در تایپش ندارد. */
type ShiftEntry = Partial<PerformanceEntry> & { value: number; hadRecentInput: boolean }

/** ترکِ صفحه — همان لحظه‌ای که گزارش فرستاده می‌شود. */
function leavePage() {
  Object.defineProperty(document, 'visibilityState', { value: 'hidden', configurable: true })
  document.dispatchEvent(new Event('visibilitychange'))
}

beforeEach(() => {
  Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true })
  vi.spyOn(performance, 'getEntriesByType').mockReturnValue([])
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('رتبه‌بندیِ سنجه‌ها', () => {
  it('آستانه‌های گوگل را رعایت می‌کند', () => {
    expect(rate('LCP', 2400)).toBe('good')
    expect(rate('LCP', 2500)).toBe('good')
    expect(rate('LCP', 3000)).toBe('needs-improvement')
    expect(rate('LCP', 5001)).toBe('poor')

    expect(rate('CLS', 0.1)).toBe('good')
    expect(rate('CLS', 0.15)).toBe('needs-improvement')
    expect(rate('CLS', 0.3)).toBe('poor')

    expect(rate('INP', 200)).toBe('good')
    expect(rate('INP', 500)).toBe('poor')
  })
})

describe('جمع‌آوری', () => {
  it('پیش از ترکِ صفحه چیزی گزارش نمی‌کند', () => {
    const observer = installObserver()
    const report = vi.fn()

    collectWebVitals(report)
    observer.emit('largest-contentful-paint', [{ startTime: 1840 }])

    /*
     * ⚠️ مهم‌ترین ادعا: LCP و CLS تا لحظه‌ی آخر می‌توانند عوض شوند. گزارشِ
     * زودهنگام یعنی ثبتِ عددی که هنوز نهایی نشده.
     */
    expect(report).not.toHaveBeenCalled()
  })

  it('هنگامِ ترکِ صفحه، آخرین مقدارِ LCP را می‌فرستد', () => {
    const observer = installObserver()
    const report = vi.fn()

    collectWebVitals(report)

    observer.emit('largest-contentful-paint', [{ startTime: 900 }])
    // تصویری که دیر می‌رسد LCP را جابه‌جا می‌کند — آخری معتبر است
    observer.emit('largest-contentful-paint', [{ startTime: 2100 }])

    leavePage()

    const lcp = reported(report, 'LCP')

    expect(lcp).toEqual({ name: 'LCP', value: 2100, rating: 'good' })
  })

  /**
   * ⚠️ CLS مجموعِ **بدترین پنجره** است، نه مجموعِ کل.
   *
   * تعریفِ گوگل «بزرگ‌ترین دسته‌ی پرش‌ها» است: پرش‌هایی که کمتر از یک ثانیه
   * از هم فاصله دارند. جمعِ ساده‌ی همه‌ی پرش‌ها عددِ بدتری می‌دهد و با آنچه
   * Search Console نشان می‌دهد نمی‌خواند.
   */
  it('CLS را پنجره‌ای حساب می‌کند نه جمعِ کل', () => {
    const observer = installObserver()
    const report = vi.fn()

    collectWebVitals(report)

    // دسته‌ی اول: دو پرشِ نزدیک به هم ⇒ ۰٫۰۶
    observer.emit('layout-shift', [
      { startTime: 100, value: 0.04, hadRecentInput: false },
      { startTime: 500, value: 0.02, hadRecentInput: false },
    ])

    // دسته‌ی دوم، خیلی دیرتر ⇒ پنجره‌ی تازه با ۰٫۰۳
    observer.emit('layout-shift', [{ startTime: 9000, value: 0.03, hadRecentInput: false }])

    leavePage()

    const cls = reported(report, 'CLS')

    // بدترین پنجره ۰٫۰۶ است؛ جمعِ کل ۰٫۰۹ می‌شد
    expect(cls?.value).toBeCloseTo(0.06, 4)
  })

  it('پرشی که بلافاصله پس از کنشِ کاربر است شمرده نمی‌شود', () => {
    const observer = installObserver()
    const report = vi.fn()

    collectWebVitals(report)

    // بازشدنِ یک آکاردئون با کلیکِ کاربر، پرشِ ناخواسته نیست
    observer.emit('layout-shift', [{ startTime: 100, value: 0.5, hadRecentInput: true }])

    leavePage()

    expect(reported(report, 'CLS')).toBeUndefined()
  })

  it('FCP را از ورودیِ paint برمی‌دارد', () => {
    const observer = installObserver()
    const report = vi.fn()

    collectWebVitals(report)

    observer.emit('paint', [
      { name: 'first-paint', startTime: 700 },
      { name: 'first-contentful-paint', startTime: 1200 },
    ])

    leavePage()

    const fcp = reported(report, 'FCP')

    expect(fcp).toEqual({ name: 'FCP', value: 1200, rating: 'good' })
  })

  it('TTFB را از زمان‌بندیِ ناوبری می‌گیرد', () => {
    installObserver()
    vi.spyOn(performance, 'getEntriesByType').mockReturnValue([
      { responseStart: 640 } as unknown as PerformanceEntry,
    ])

    const report = vi.fn()

    collectWebVitals(report)
    leavePage()

    const ttfb = reported(report, 'TTFB')

    expect(ttfb).toEqual({ name: 'TTFB', value: 640, rating: 'good' })
  })

  /**
   * ⚠️ گزارش فقط **یک بار** می‌رود.
   *
   * کاربر ممکن است چند بار بین تب‌ها جابه‌جا شود؛ هر بار یک بسته‌ی تکراری
   * یعنی جدول پر می‌شود از یک بازدید و صدکِ ۷۵ به‌هم می‌ریزد.
   */
  it('با چند بار پنهان‌شدن، فقط یک بار گزارش می‌دهد', () => {
    const observer = installObserver()
    const report = vi.fn()

    collectWebVitals(report)
    observer.emit('largest-contentful-paint', [{ startTime: 1500 }])

    leavePage()
    leavePage()

    expect(report).toHaveBeenCalledTimes(1)
  })

  it('در مرورگرِ بدونِ PerformanceObserver نمی‌ترکد', () => {
    vi.stubGlobal('PerformanceObserver', undefined)

    expect(() => collectWebVitals(vi.fn())).not.toThrow()
  })
})
