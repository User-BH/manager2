import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, sep } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { isSupportTopicId } from '@/features/landing/support/supportContent'

/**
 * فرم‌ها، مسیرها و پارامترهای آدرس (مرحلهٔ ۵، فاز ۴ — بندهای ۲۲ تا ۳۲).
 *
 * ─── آنچه اندازه‌گیری نشان داد ────────────────────────────────────────────
 * بیست فرم بررسی شدند. اعتبارسنجی، پیامِ خطا، غیرفعال‌سازی، ارسال و
 * دسترس‌پذیری در **هر بیست‌تا** بود؛ خطای سرور در ۱۸ و بازخوردِ موفقیت در
 * ۱۶ تا.
 *
 * ⚠️ ولی سه چیزِ واقعی کم بود، و هر سه در همین فاز بسته شد.
 */

const ROOT = 'resources/js'
const norm = (p: string): string => p.split(sep).join('/')

function sources(): string[] {
  const found: string[] = []

  const walk = (dir: string): void => {
    for (const entry of readdirSync(dir)) {
      const path = join(dir, entry)

      if (statSync(path).isDirectory()) walk(path)
      else if (/\.(ts|tsx)$/.test(path) && !/\.d\.ts$/.test(path)) found.push(norm(path))
    }
  }

  walk(ROOT)

  return found
}

/** ⚠️ کامنت‌ها پاک می‌شوند — تلهٔ تکرارشوندهٔ این پروژه. */
function codeOf(path: string): string {
  return readFileSync(path, 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/^\s*\/\/.*$/gm, '')
}

const files = sources().map((path) => ({ path: path.replace(`${ROOT}/`, ''), code: codeOf(path) }))

describe('فرم‌ها و مسیرها', () => {
  it('فایل‌ها واقعاً خوانده شده‌اند', () => {
    expect(files.length).toBeGreaterThan(150)
  })

  /**
   * 🔴 دکمهٔ درگاه پس از کلیکِ اول قفل می‌شود (بند ۲۴).
   *
   * ─── چرا این مورد از بقیهٔ فرم‌ها جدا بود ──────────────────────────────
   * ⚠️ این یک فرمِ **واقعیِ مرورگر** است، نه `fetch`. پس `isSubmitting`ِ
   * react-hook-form اصلاً وجود نداشت و هیچ‌چیز جلوی کلیکِ دوم را
   * نمی‌گرفت.
   *
   * 🔴 و پیامدش پولی بود: سرور idempotent است و ردیفِ دوم نمی‌ساخت، ولی
   * هر POST یک `ref_id` تازه از بانک می‌گرفت و قبلی را بازنویسی می‌کرد.
   * بازگشتِ مرورگر با refِ کهنه یعنی `verify` رد کند — پرداختِ موفق،
   * «ناموفق» ثبت شود.
   */
  it('دکمهٔ انتقال به درگاه پس از ارسال قفل می‌شود', () => {
    const page = files.find((f) => f.path.endsWith('payments/pay/PayBillPage.tsx'))

    expect(page, 'صفحهٔ پرداخت پیدا نشد؛ محافظ پوچ می‌شود.').toBeDefined()

    const code = page?.code ?? ''

    expect(code).toContain('setLeavingToGateway(true)')
    expect(code).toMatch(/disabled=\{leavingToGateway\}/)
  })

  /**
   * ⚠️ و بازگشت با دکمهٔ back دکمه را مرده رها نمی‌کند (بند ۲۹).
   *
   * 🔴 مرورگر صفحه را از bfcache برمی‌گرداند و state همان‌طور می‌ماند.
   * بدونِ `pageshow`، کاربری که از درگاه برمی‌گردد دکمه‌ای غیرفعال
   * می‌بیند و فکر می‌کند صفحه خراب است.
   */
  it('بازگشت از درگاه دکمه را آزاد می‌کند', () => {
    const page = files.find((f) => f.path.endsWith('payments/pay/PayBillPage.tsx'))
    const code = page?.code ?? ''

    /*
     * ⚠️ ادعا روی **ثبتِ** شنونده، نه صرفاً وجودِ واژه.
     *
     * 🔴 نسخهٔ اول `toContain('pageshow')` بود و خرابکاری از تورش رد شد:
     * با حذفِ `addEventListener`، خطِ `removeEventListener` همچنان واژه را
     * داشت و تست سبز ماند.
     */
    expect(code).toMatch(/addEventListener\('pageshow'/)
    expect(code).toContain('event.persisted')
  })

  /**
   * 🔴 هیچ پارامترِ آدرسی با **ادعای نوع** خوانده نمی‌شود (بندهای ۱۴ و ۳۱).
   *
   * ─── موردی که همین فاز بست ─────────────────────────────────────────────
   * `SupportPage` می‌نوشت `params.get('topic') as SupportTopicId | null`.
   * آدرس را کاربر می‌سازد و لینکش را می‌شود جای دیگری فرستاد؛ ادعای نوع
   * نه Type-safe است و نه Validated.
   */
  it('هیچ پارامترِ آدرسی با ادعای نوع خوانده نمی‌شود', () => {
    const offenders: string[] = []

    for (const { path, code } of files) {
      for (const line of code.split('\n')) {
        if (!/(?:searchParams|params)\.get\(/.test(line)) continue
        if (!/\bas\s+[A-Z]/.test(line)) continue

        offenders.push(`${path}: ${line.trim()}`)
      }
    }

    expect(offenders).toEqual([])
  })

  /** ⚠️ ادعای مثبت: پارامترها واقعاً خوانده می‌شوند، وگرنه تستِ بالا پوچ است. */
  it('پارامترهای آدرس واقعاً پیدا شده‌اند', () => {
    const reads = files.reduce(
      (sum, { code }) => sum + (code.match(/(?:searchParams|params)\.get\(/g) ?? []).length,
      0,
    )

    expect(reads).toBeGreaterThan(5)
  })

  describe('نگهبانِ موضوعِ پشتیبانی', () => {
    it('شناسه‌های واقعی را می‌پذیرد', () => {
      for (const id of ['faq', 'terms', 'privacy', 'contact']) {
        expect(isSupportTopicId(id), `«${id}» باید معتبر باشد.`).toBe(true)
      }
    })

    /** 🔴 هر چیزِ دیگری یعنی «موضوعی خواسته نشده». */
    it('هر چیزِ دیگری را رد می‌کند', () => {
      const invalid: Array<[string, unknown]> = [
        ['رشتهٔ ناشناخته', 'hack'],
        ['خالی', ''],
        ['null', null],
        ['عدد', 3],
        ['شیء', {}],
      ]

      for (const [label, bad] of invalid) {
        expect(isSupportTopicId(bad), `«${label}» نباید معتبر باشد.`).toBe(false)
      }
    })
  })

  /**
   * 🔴 خرابیِ یک مسیر کلِ برنامه را پایین نمی‌کشد (بند ۳۰).
   *
   * ⚠️ `ErrorBoundary` با `resetKey={pathname}` دورِ `<Outlet />` نشسته،
   * پس با رفتن به صفحهٔ بعدی خودش را بازنشانی می‌کند. بدونِ `resetKey`،
   * یک خطا صفحه را تا رفرشِ کامل قفل نگه می‌داشت.
   */
  it('هر مسیر مرزِ خطای خودش را دارد', () => {
    const layout = files.find((f) => f.path.endsWith('layout/DashboardLayout.tsx'))

    expect(layout).toBeDefined()

    const code = layout?.code ?? ''

    expect(code).toContain('<ErrorBoundary')
    expect(code).toMatch(/resetKey=\{pathname\}/)
    expect(code).toContain('<Outlet')
  })

  /**
   * ⚠️ محافظِ مسیر پیش از پاسخِ `/me` کاربر را بیرون نمی‌اندازد (بند ۲۸).
   *
   * 🔴 بدونِ حالتِ `isLoading`، هر بار رفرشِ صفحه کاربرِ واردشده یک لحظه
   * به `/auth` پرت می‌شد — همان «Login → Flash → Dashboard» که فایلِ
   * توکن هم صریحاً ممنوعش کرده.
   */
  it('رفرشِ صفحه کاربر را به ورود پرت نمی‌کند', () => {
    const guard = files.find((f) => f.path.endsWith('app/ProtectedRoute.tsx'))

    expect(guard).toBeDefined()

    const code = guard?.code ?? ''

    /*
     * ⚠️ مقایسه روی **خودِ شرط‌ها**، نه اولین ظهورِ نام.
     *
     * 🔴 نسخهٔ اول `indexOf('isLoading')` را با `indexOf('isAuthenticated')`
     * می‌سنجید و قرمز شد — چون هر دو در خطِ **تخریبِ شیء** هم می‌آیند و
     * ترتیبشان آنجا ربطی به منطق ندارد. تست چیزِ اشتباهی را می‌سنجید.
     */
    const loadingCheck = code.indexOf('if (isLoading)')
    const authCheck = code.indexOf('if (!isAuthenticated)')

    expect(loadingCheck, 'شرطِ بارگذاری پیدا نشد.').toBeGreaterThan(-1)
    expect(authCheck, 'شرطِ احراز هویت پیدا نشد.').toBeGreaterThan(-1)
    expect(loadingCheck, 'تصمیمِ خروج پیش از پایانِ بارگذاری گرفته می‌شود.').toBeLessThan(authCheck)
  })
})

/**
 * تغییراتِ ذخیره‌نشده (بند ۲۵).
 *
 * ─── 🔴 چه چیزی نبود ───────────────────────────────────────────────────────
 * هیچ‌جای پروژه `beforeunload` نداشت. کاربری که دقایقی تنظیماتِ مجتمع را
 * پر کرده بود، با یک بستنِ تبِ اشتباهی همه‌اش را از دست می‌داد — بی‌هیچ
 * پرسشی.
 */
describe('هشدارِ تغییراتِ ذخیره‌نشده', () => {
  async function setup(isDirty: boolean) {
    const { renderHook } = await import('@testing-library/react')
    const { useUnsavedChanges } = await import('@/shared/hooks/useUnsavedChanges')

    const add = vi.spyOn(window, 'addEventListener')
    const remove = vi.spyOn(window, 'removeEventListener')

    const view = renderHook(() => useUnsavedChanges(isDirty))

    return { add, remove, view }
  }

  it('با فرمِ دست‌نخورده هیچ شنونده‌ای ثبت نمی‌کند', async () => {
    const { add } = await setup(false)

    expect(add.mock.calls.filter(([type]) => type === 'beforeunload')).toHaveLength(0)

    add.mockRestore()
  })

  /** 🔴 فرمِ تغییرکرده باید پیش از بستنِ صفحه بپرسد. */
  it('با تغییرِ ذخیره‌نشده شنونده ثبت می‌کند', async () => {
    const { add } = await setup(true)

    expect(add.mock.calls.filter(([type]) => type === 'beforeunload')).toHaveLength(1)

    add.mockRestore()
  })

  /** ⚠️ و با unmount پاکش می‌کند، وگرنه هشدار روی صفحه‌های بعدی هم می‌ماند. */
  it('هنگام unmount شنونده را برمی‌دارد', async () => {
    const { remove, view } = await setup(true)

    view.unmount()

    expect(remove.mock.calls.filter(([type]) => type === 'beforeunload')).toHaveLength(1)

    remove.mockRestore()
  })

  /**
   * ⚠️ و فرمِ تنظیمات واقعاً از آن استفاده می‌کند.
   *
   * 🔴 بدونِ این، هوک می‌توانست وجود داشته باشد و هیچ‌جا صدا زده نشود —
   * همان الگوی «محافظِ درست، در جای نادرست صدا زده نشده».
   */
  it('بلندترین فرمِ پروژه از آن استفاده می‌کند', () => {
    const settings = files.find((f) => f.path.endsWith('settings/ComplexSettingsPage.tsx'))

    expect(settings).toBeDefined()
    expect(settings?.code).toContain('useUnsavedChanges(isDirty)')
  })
})
