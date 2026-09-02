import { beforeEach, describe, expect, it } from 'vitest'

import {
  observeServerBuild,
  pageBuild,
  resetAppBuildState,
  useAppBuildStore,
} from '@/shared/pwa/appBuild'

/**
 * تشخیصِ Mixed App Versions (R61).
 *
 * ─── 🔴 مسئله ──────────────────────────────────────────────────────────────
 * کاربری که تب را باز گذاشته، جاوااسکریپتِ دیروز را در حافظه دارد. ما امروز
 * استقرار می‌دهیم. از آن لحظه آن تب با APIی حرف می‌زند که کدش عوض شده.
 *
 * ⚠️ خرابی‌اش «خطا» نیست، **رفتارِ اشتباه** است — فیلدی که دیگر نیست
 * `undefined` می‌شود و هیچ‌جا ثبت نمی‌شود.
 *
 * ─── و چرا نیمی از این تست‌ها دربارهٔ **ساکت ماندن** است ────────────────────
 * ⚠️ هشدارِ کاذب سریع‌تر از هر چیزی اعتمادِ کاربر را از بین می‌برد. نواری
 * که بی‌دلیل بالا بیاید، دفعهٔ بعد که واقعاً لازم است هم نادیده گرفته
 * می‌شود. پس «کِی حرف نزند» به‌اندازهٔ «کِی حرف بزند» سنجیده می‌شود.
 */

function setPageBuild(value: string | null): void {
  document.head.querySelector('meta[name="app-build"]')?.remove()

  if (value === null) return

  const meta = document.createElement('meta')
  meta.name = 'app-build'
  meta.content = value
  document.head.append(meta)
}

describe('شناسهٔ ساختِ این صفحه', () => {
  beforeEach(() => {
    resetAppBuildState()
    setPageBuild('aaa111')
  })

  it('از متاتگ خوانده می‌شود', () => {
    expect(pageBuild()).toBe('aaa111')
  })

  it('نبودِ متاتگ خطا نمی‌دهد', () => {
    setPageBuild(null)

    expect(pageBuild()).toBeNull()
  })
})

describe('مقایسه با نسخه‌ای که سرور اعلام می‌کند', () => {
  beforeEach(() => {
    resetAppBuildState()
    setPageBuild('aaa111')
  })

  /** 🔴 ادعای اصلی. */
  it('نسخهٔ متفاوت یعنی استقرار انجام شده', () => {
    observeServerBuild('bbb222')

    expect(useAppBuildStore.getState().serverBuild).toBe('bbb222')
  })

  it('نسخهٔ یکسان چیزی را روشن نمی‌کند', () => {
    observeServerBuild('aaa111')

    expect(useAppBuildStore.getState().serverBuild).toBeNull()
  })

  /** پاسخ از کش یا مسیری بیرون از API؛ هدری نیامده که مقایسه شود. */
  it('نبودِ هدر ساکت است', () => {
    observeServerBuild(undefined)
    observeServerBuild(null)
    observeServerBuild('   ')

    expect(useAppBuildStore.getState().serverBuild).toBeNull()
  })

  /** HTMLِ قدیمی‌تر از این قابلیت؛ چیزی برای مقایسه نیست. */
  it('نبودِ متاتگ ساکت است', () => {
    setPageBuild(null)

    observeServerBuild('bbb222')

    expect(useAppBuildStore.getState().serverBuild).toBeNull()
  })

  /**
   * ⚠️ سرورِ توسعه manifest ندارد و مقدارِ ثابتِ `dev` می‌دهد.
   *
   * بدونِ این شرط، هر توسعه‌دهنده‌ای که با `npm run dev` کار می‌کند و
   * HTMLش از یک buildِ قدیمی آمده، نوارِ «نسخهٔ تازه» را دائم می‌دید.
   */
  it('حالتِ توسعه هشدار نمی‌دهد', () => {
    observeServerBuild('dev')
    expect(useAppBuildStore.getState().serverBuild).toBeNull()

    setPageBuild('dev')
    observeServerBuild('bbb222')
    expect(useAppBuildStore.getState().serverBuild).toBeNull()
  })

  /**
   * ⚠️ هر پاسخِ API این تابع را صدا می‌زند.
   *
   * اگر هر بار state را می‌نوشت، zustand هر مصرف‌کننده را دوباره رندر
   * می‌کرد — نواری که با هر درخواست چشمک بزند.
   */
  it('برای همان نسخهٔ سرور دوباره نمی‌نویسد', () => {
    observeServerBuild('bbb222')

    const first = useAppBuildStore.getState()

    observeServerBuild('bbb222')
    observeServerBuild('bbb222')

    expect(useAppBuildStore.getState()).toBe(first)
  })
})

/**
 * 🔴 رهگیرِ واقعی، نه منطقِ جدا.
 *
 * ⚠️ این بلوک را **خرابکاری** به من تحمیل کرد: برداشتنِ رهگیرِ مسیرِ
 * خطا در `http.ts` از تور رد شد، چون `api.test.ts` کلِ ماژولِ `http` را
 * mock می‌کند و هیچ تستی نمونهٔ واقعی را نمی‌راند.
 *
 * یعنی ادعای «پاسخِ خطا هم شناسه را گزارش می‌کند» فقط سمتِ سرور اثبات
 * شده بود، نه سمتِ کلاینت.
 */
describe('رهگیرِ نمونهٔ واقعیِ axios', () => {
  beforeEach(() => {
    resetAppBuildState()
    setPageBuild('aaa111')
  })

  it('از پاسخِ موفق شناسه را برمی‌دارد', async () => {
    const { http } = await import('@/shared/lib/http')

    http.defaults.adapter = (config) =>
      Promise.resolve({
        data: { ok: true },
        status: 200,
        statusText: 'OK',
        headers: { 'x-app-build': 'bbb222' },
        config,
      })

    await http.get('/whatever')

    expect(useAppBuildStore.getState().serverBuild).toBe('bbb222')
  })

  /**
   * ⚠️ مهم‌ترین حالت.
   *
   * وقتی نسخهٔ فرانت و API از هم جدا می‌افتند، اولین نشانه معمولاً
   * **خطاست** — ۴۲۲ روی فیلدی که دیگر نیست، ۴۰۴ روی مسیری که عوض شده.
   * اگر فقط مسیرِ موفق خوانده می‌شد، دقیقاً همان موردی که این سازوکار
   * برایش هست از تور رد می‌شد.
   */
  it('از پاسخِ خطا هم شناسه را برمی‌دارد', async () => {
    const { http } = await import('@/shared/lib/http')

    /*
     * ⚠️ آداپتورِ سفارشی خودش باید رد کند.
     *
     * اولین نسخه‌ی این تست با وضعیتِ ۴۰۱ **resolve** می‌کرد و تست شکست
     * خورد — چون axios `validateStatus` را روی خروجیِ آداپتورِ سفارشی
     * اعمال نمی‌کند؛ آن کار در `settle` است که آداپتورِ داخلی صدایش
     * می‌زند. پس شکلِ واقعیِ رد را خودمان می‌سازیم.
     */
    http.defaults.adapter = (config) =>
      Promise.reject(
        Object.assign(new Error('Request failed with status code 401'), {
          isAxiosError: true,
          config,
          response: {
            data: { message: 'احراز هویت لازم است.' },
            status: 401,
            statusText: 'Unauthorized',
            headers: { 'x-app-build': 'ccc333' },
            config,
          },
        }),
      )

    await expect(http.get('/whatever')).rejects.toBeDefined()

    expect(useAppBuildStore.getState().serverBuild).toBe('ccc333')
  })
})
