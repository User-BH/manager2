import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * پاک‌سازیِ کشِ دستگاه هنگام خروج (فاز ۵).
 *
 * ─── 🔴 چرا این تست ساخته شد ───────────────────────────────────────────────
 * کد از R60 درست بود و کامنتِ مفصلی هم داشت: خروج باید کشِ API را پاک کند،
 * وگرنه قبض و موجودی و پیام‌های همین کاربر روی دستگاه می‌مانند و نفرِ بعدی
 * — یا خودش پس از خروج — می‌تواند آفلاین ببیندشان. نشستِ سرور بسته شده،
 * ولی نسخه‌ی کش‌شده سرورش را نمی‌پرسد.
 *
 * ⚠️ **ولی هیچ تستی نداشت.** خرابکاریِ فاز ۵ نشان داد: با برداشتنِ
 * `await clearApiCache()` از مسیرِ خروج، هیچ‌کدام از ۳۹۸ تستِ فرانت قرمز
 * نشد.
 *
 * یعنی محافظت به «کسی این خط را پاک نکند» وابسته بود، نه به چیزی که
 * می‌سنجدش.
 */
describe('خروج، کشِ دستگاه را پاک می‌کند', () => {
  const deleted: string[] = []

  beforeEach(() => {
    vi.resetModules()
    deleted.length = 0

    /*
     * کش‌های ساختگی — دو سطلِ حساس و دو سطلِ بی‌ضرر.
     *
     * ⚠️ وجودِ سطل‌های بی‌ضرر عمدی است: بدونشان، تستی که «همه پاک شدند»
     * را بسنجد هم سبز می‌ماند، و آن‌وقت پاک‌کردنِ بی‌رویه‌ی دارایی‌ها
     * (که فقط ورودِ بعدی را کُند می‌کند) دیده نمی‌شد.
     */
    vi.stubGlobal('caches', {
      keys: () =>
        Promise.resolve([
          'sakena-api-v4',
          'sakena-pages-v4',
          'sakena-assets-v4',
          'sakena-images-v4',
        ]),
      delete: (key: string) => {
        deleted.push(key)

        return Promise.resolve(true)
      },
    })
  })

  it('سطل‌های دادهٔ کاربر پاک می‌شوند', async () => {
    const { clearApiCache } = await import('@/shared/pwa/registerServiceWorker')

    await clearApiCache()

    expect(deleted).toContain('sakena-api-v4')
    expect(deleted).toContain('sakena-pages-v4')
  })

  /**
   * ⚠️ و دارایی‌ها عمداً می‌مانند.
   *
   * چیزی از کاربر در آن‌ها نیست و پاک‌کردنشان فقط یعنی ورودِ بعدی کُند
   * باشد. محافظی که بیش از لازم پاک کند، هزینه‌ای می‌دهد که دلیلی ندارد.
   */
  it('سطلِ دارایی و تصویر دست‌نخورده می‌ماند', async () => {
    const { clearApiCache } = await import('@/shared/pwa/registerServiceWorker')

    await clearApiCache()

    expect(deleted).not.toContain('sakena-assets-v4')
    expect(deleted).not.toContain('sakena-images-v4')
  })

  /**
   * 🔴 ادعای اصلی: خودِ مسیرِ **خروج** این کار را بکند.
   *
   * ⚠️ دو تستِ بالا فقط می‌گویند تابع درست کار می‌کند. چیزی که خرابکاری
   * نشان داد این بود که کسی **صدایش بزند** — و همان بود که تست نداشت.
   */
  it('مسیرِ خروج خودش پاک‌سازی را صدا می‌زند', async () => {
    vi.doMock('@/shared/lib/api', () => ({
      api: () => Promise.resolve({}),
      ApiError: class extends Error {},
    }))

    const { useAuthStore } = await import('@/shared/stores/authStore')

    await useAuthStore.getState().logout()

    expect(deleted).toContain('sakena-api-v4')
    expect(deleted).toContain('sakena-pages-v4')
  })

  /**
   * ⚠️ و حتی اگر درخواستِ خروج شکست بخورد.
   *
   * 🔴 قطعیِ شبکه دقیقاً همان لحظه‌ای است که کاربر روی دستگاهِ مشترک
   * «خروج» می‌زند و می‌رود. اگر پاک‌سازی به موفقیتِ درخواست گره بخورد،
   * بدترین حالت بدونِ محافظ می‌ماند.
   */
  it('حتی وقتی درخواستِ خروج شکست بخورد هم پاک می‌شود', async () => {
    vi.doMock('@/shared/lib/api', () => ({
      api: () => Promise.reject(new Error('offline')),
      ApiError: class extends Error {},
    }))

    const { useAuthStore } = await import('@/shared/stores/authStore')

    /*
     * ⚠️ `logout` خطا را دوباره پرتاب می‌کند — `try/finally` بدونِ
     * `catch`. پس ادعا این نیست که بی‌صدا رد شود؛ ادعا این است که
     * پاک‌سازی **با وجودِ خطا** انجام شده باشد.
     */
    await expect(useAuthStore.getState().logout()).rejects.toThrow()

    expect(deleted).toContain('sakena-api-v4')
  })
  /**
   * 🔴 و رابط نباید کاربر را روی صفحه نگه دارد.
   *
   * ─── نقصی که همین تست پیدا کرد ──────────────────────────────────────────
   * ⚠️ `UserMenu.handleLogout` هیچ `catch` نداشت. چون `logout()` خطا را
   * دوباره پرتاب می‌کند، در قطعیِ شبکه دو خطِ بعدی **هرگز اجرا نمی‌شدند**:
   * منو باز می‌ماند و ناوبری انجام نمی‌شد.
   *
   * کاربر روی دستگاهِ مشترک «خروج» را می‌زد، داشبورد را می‌دید، و می‌رفت —
   * با این باور که خارج نشده. در حالی که حالتِ محلی و کش پاک شده بودند.
   *
   * ⚠️ این تست سورس را می‌خواند و نه رفتار را، و دلیلش صریح است: رندرِ
   * `UserMenu` به روتر و زمینه‌ی احراز نیاز دارد و ادعای اینجا فقط یک
   * چیز است — اینکه خطای خروج بلعیده شود.
   */
  it('رابط خطای خروج را می‌بلعد و کاربر را بیرون می‌برد', async () => {
    const { readFileSync } = await import('node:fs')
    const { resolve } = await import('node:path')

    const source = readFileSync(
      resolve(process.cwd(), 'resources/js/shared/layout/UserMenu.tsx'),
      'utf8',
    ).replace(/\/\*[\s\S]*?\*\//g, '')

    expect(source).toMatch(/try\s*\{\s*await logout\(\)\s*\}\s*catch/)
    expect(source).toContain('leaveToAuth()')
  })
})
