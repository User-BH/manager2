import { beforeEach, describe, expect, it, vi } from 'vitest'

import {
  definitelyOffline,
  markOffline,
  markOnline,
  resetConnectionState,
  useConnectionStore,
  watchConnection,
} from '@/shared/pwa/connection'

/**
 * وضعیتِ اتصال (R62).
 *
 * ─── 🔴 چه چیزی نبود ───────────────────────────────────────────────────────
 * ⚠️ اندازه‌گیری شد: در کلِ فرانت **یک بار هم** `navigator.onLine` خوانده
 * نمی‌شد و هیچ نشانگرِ وضعیتی وجود نداشت. کاربرِ آفلاین فقط می‌دید که
 * درخواست‌هایش یکی‌یکی با پیامِ عمومی شکست می‌خورند.
 *
 * ─── و چرا این تست‌ها بیشتر دربارهٔ **بی‌اعتمادی** به مرورگر است ────────────
 * `navigator.onLine` فقط می‌گوید «کارتِ شبکه به چیزی وصل است». مودمی که
 * خودش قطع است هم `true` می‌دهد. پس جهتِ منفی‌اش قابلِ اعتماد است و جهتِ
 * مثبتش نه — و این تفاوت باید سنجیده شود، نه فرض.
 */

function setBrowserOnline(value: boolean): void {
  Object.defineProperty(navigator, 'onLine', { value, configurable: true })
}

describe('اعتماد به پرچمِ مرورگر', () => {
  beforeEach(() => {
    resetConnectionState()
    setBrowserOnline(true)
  })

  it('«آفلاین»ِ مرورگر قطعی است', () => {
    setBrowserOnline(false)

    expect(definitelyOffline()).toBe(true)
  })

  /**
   * 🔴 مهم‌ترین ادعای این فایل.
   *
   * ⚠️ اگر به `true` اعتماد می‌کردیم، کاربری که به مودمِ قطع وصل است
   * نشانگرِ سبز می‌دید و درخواست‌هایش یکی‌یکی می‌شکست — بدترین ترکیبِ
   * ممکن: نشانگری که دروغ می‌گوید.
   */
  it('«آنلاین»ِ مرورگر چیزی را ثابت نمی‌کند', () => {
    setBrowserOnline(true)

    expect(definitelyOffline()).toBe(false)

    // ...ولی همین‌که یک درخواست واقعاً نرسد، حرفِ مرورگر بی‌اثر است
    markOffline()

    expect(useConnectionStore.getState().state).toBe('offline')
  })
})

describe('حقیقت از روی درخواست‌های واقعی', () => {
  beforeEach(() => {
    resetConnectionState()
    setBrowserOnline(true)
  })

  it('پاسخِ رسیده یعنی قطعاً آنلاینیم', () => {
    markOffline()
    markOnline()

    expect(useConnectionStore.getState().state).toBe('online')
  })

  /**
   * ⚠️ نوشتنِ ناموفق معنیِ متفاوتی از خواندنِ ناموفق دارد.
   *
   * خواندن یعنی «الان نمی‌بینی». نوشتن یعنی **کاری که کاربر خواسته انجام
   * نشده** — و او باید بداند، حتی پس از برگشتنِ اتصال.
   */
  it('نوشتنِ ازدست‌رفته جدا نگه داشته می‌شود', () => {
    markOffline(true)

    expect(useConnectionStore.getState().lastWriteFailed).toBe(true)
  })

  it('خواندنِ ناموفق پرچمِ نوشتن را روشن نمی‌کند', () => {
    markOffline(false)

    expect(useConnectionStore.getState().lastWriteFailed).toBe(false)
  })

  it('برگشتنِ اتصال، پرچمِ نوشتنِ ازدست‌رفته را هم پاک می‌کند', () => {
    markOffline(true)
    markOnline()

    expect(useConnectionStore.getState()).toMatchObject({
      state: 'online',
      lastWriteFailed: false,
    })
  })

  /**
   * ⚠️ هر پاسخِ موفق این را صدا می‌زند.
   *
   * اگر هر بار state را می‌نوشت، zustand هر مصرف‌کننده را دوباره رندر
   * می‌کرد — نشانگری که با هر درخواست چشمک بزند.
   */
  it('وقتی چیزی عوض نشده، state تازه نمی‌نویسد', () => {
    const before = useConnectionStore.getState()

    markOnline()
    markOnline()

    expect(useConnectionStore.getState()).toBe(before)
  })
})

describe('رویدادهای مرورگر', () => {
  beforeEach(() => {
    resetConnectionState()
    setBrowserOnline(true)
  })

  it('رویدادِ offline بی‌درنگ اعمال می‌شود', () => {
    const stop = watchConnection()

    window.dispatchEvent(new Event('offline'))

    expect(useConnectionStore.getState().state).toBe('offline')

    stop()
  })

  /**
   * ⚠️ رویدادِ `online` **آنلاین‌بودن را ثابت نمی‌کند** — همان تله‌ی بالا.
   * پس فقط حالتِ «شاید برگشته» می‌سازد و تأییدِ نهایی با اولین پاسخِ
   * واقعیِ سرور می‌آید.
   */
  it('رویدادِ online فقط «در حال بررسی» می‌سازد، نه «آنلاین»', () => {
    const stop = watchConnection()

    window.dispatchEvent(new Event('offline'))
    window.dispatchEvent(new Event('online'))

    expect(useConnectionStore.getState().state).toBe('retrying')

    // و تأیید فقط با پاسخِ واقعی
    markOnline()
    expect(useConnectionStore.getState().state).toBe('online')

    stop()
  })

  it('پس از توقف، رویدادها دیگر اثری ندارند', () => {
    const stop = watchConnection()
    stop()

    window.dispatchEvent(new Event('offline'))

    expect(useConnectionStore.getState().state).toBe('online')
  })
})

describe('نوشتن وقتی قطعاً آفلاینیم', () => {
  beforeEach(() => {
    resetConnectionState()

    /*
     * ⚠️ عمداً `vi.resetModules()` صدا زده **نمی‌شود**.
     *
     * اولین نسخه‌ی این بلوک آن را داشت و تست شکست خورد: با ریست، ماژولِ
     * `api` نسخه‌ی تازه‌ای از `connection` می‌گرفت و روی **store دیگری**
     * می‌نوشت — نه آن که تست بالا import کرده بود. یعنی محصول درست کار
     * می‌کرد و تست به دلیلِ اشتباه قرمز بود.
     */
  })

  /**
   * 🔴 درخواستِ نوشتنی اصلاً فرستاده نمی‌شود.
   *
   * ⚠️ بدونِ این، درخواست بیست ثانیه پشتِ timeout می‌ماند و کاربر یا
   * دوباره می‌زند (و بدترش می‌کند) یا صفحه را می‌بندد و متنش را از دست
   * می‌دهد.
   */
  it('نوشتن بی‌درنگ و بدونِ تماسِ شبکه رد می‌شود', async () => {
    setBrowserOnline(false)

    const { api, ApiError } = await import('@/shared/lib/api')
    const { http } = await import('@/shared/lib/http')

    const adapter = vi.fn()
    http.defaults.adapter = adapter

    await expect(api('/announcements', { method: 'POST', body: {} })).rejects.toBeInstanceOf(
      ApiError,
    )

    expect(adapter).not.toHaveBeenCalled()
    expect(useConnectionStore.getState().lastWriteFailed).toBe(true)
  })

  /**
   * ⚠️ متقارن: خواندن نباید مسدود شود.
   *
   * اگر همه‌چیز مسدود می‌شد، مسیرهایی که ممکن است از کشِ مرورگر یا
   * پوسته‌ی برنامه بیایند هم بی‌دلیل می‌شکستند — و بدتر، تستِ بالا
   * چیزی را ثابت نمی‌کرد جز «همه‌چیز مسدود است».
   */
  it('خواندن مسدود نمی‌شود', async () => {
    setBrowserOnline(false)

    const { api } = await import('@/shared/lib/api')
    const { http } = await import('@/shared/lib/http')

    const adapter = vi.fn().mockResolvedValue({
      data: { ok: true },
      status: 200,
      statusText: 'OK',
      headers: {},
      config: {},
    })

    http.defaults.adapter = adapter

    await api('/features')

    expect(adapter).toHaveBeenCalled()
  })
})

/**
 * 🔴 سیم‌کشیِ واقعی، نه فقط توابعِ جدا.
 *
 * ⚠️ این بلوک را **خرابکاری** به من تحمیل کرد: برداشتنِ `markOffline` از
 * رهگیرِ خطای `http.ts` از تور رد شد، چون همه‌ی تست‌های بالا آن توابع را
 * مستقیم صدا می‌زدند و هیچ‌کدام یک شکستِ شبکه‌ای واقعی را از میانِ
 * رهگیر نمی‌راند.
 *
 * یعنی «وضعیت از روی درخواست‌های واقعی حساب می‌شود» ادعایی بود که
 * اثباتش نمی‌شد.
 */
describe('شکستِ شبکه‌ای از میانِ رهگیر', () => {
  beforeEach(() => {
    resetConnectionState()
    setBrowserOnline(true)
  })

  /** خطایی بدونِ `response` یعنی درخواست اصلاً به سرور نرسید. */
  const networkFailure = (config: unknown) =>
    Promise.reject(Object.assign(new Error('Network Error'), { isAxiosError: true, config }))

  it('خواندنی که نرسد، وضعیت را آفلاین می‌کند', async () => {
    const { http } = await import('@/shared/lib/http')

    http.defaults.adapter = networkFailure

    await expect(http.get('/whatever')).rejects.toBeDefined()

    expect(useConnectionStore.getState()).toMatchObject({
      state: 'offline',
      lastWriteFailed: false,
    })
  })

  it('نوشتنی که نرسد، «تغییرِ ذخیره‌نشده» را هم علامت می‌زند', async () => {
    const { http } = await import('@/shared/lib/http')

    http.defaults.adapter = networkFailure

    await expect(http.post('/whatever', {})).rejects.toBeDefined()

    expect(useConnectionStore.getState().lastWriteFailed).toBe(true)
  })

  /**
   * 🔴 لغوِ عمدی (React Query هنگامِ باطل‌سازیِ دوباره) آفلاین نیست (مرحلهٔ ۹ · فاز ۶).
   *
   * در پیام‌رسانِ مدیر روی WebKit، «خواندم» و «ارسال» پشتِ هم درخواستِ فهرست
   * را لغو می‌کردند و صفحه با نوارِ «اتصال برقرار نیست» به حالتِ خطا می‌رفت.
   */
  it('درخواستِ لغوشده وضعیت را آفلاین نمی‌کند', async () => {
    const { http } = await import('@/shared/lib/http')

    const controller = new AbortController()
    http.defaults.adapter = (config) =>
      new Promise((_, reject) => {
        config.signal?.addEventListener?.('abort', () =>
          reject(
            Object.assign(new Error('canceled'), {
              __CANCEL__: true,
              name: 'CanceledError',
              config,
            }),
          ),
        )
      })

    const pending = http.get('/whatever', { signal: controller.signal })
    controller.abort()

    await expect(pending).rejects.toBeDefined()
    expect(useConnectionStore.getState().state).toBe('online')
  })

  /**
   * ⚠️ متقارن، و لازم: **هر پاسخی** — حتی ۵۰۰ — ثابت می‌کند اتصال هست.
   *
   * بدونِ این، «هر خطا یعنی آفلاین» هم سبز می‌شد و نشانگر با اولین
   * خطای سرور دروغ می‌گفت.
   */
  it('خطای سرور یعنی اتصال هست، نه اینکه آفلاینیم', async () => {
    const { http } = await import('@/shared/lib/http')

    markOffline()

    http.defaults.adapter = (config) =>
      Promise.reject(
        Object.assign(new Error('Request failed with status code 500'), {
          isAxiosError: true,
          config,
          response: { data: {}, status: 500, statusText: 'Server Error', headers: {}, config },
        }),
      )

    await expect(http.get('/whatever')).rejects.toBeDefined()

    expect(useConnectionStore.getState().state).toBe('online')
  })
})
