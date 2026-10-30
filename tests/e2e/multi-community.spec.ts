import { expect, test, type Page } from '@playwright/test'

/**
 * هسته‌ی چندمجتمعی در مرورگرِ واقعی — مرحلهٔ ۱۳ · فاز ۳ (ایرادات ۱۰، بندهای ۳۳ تا ۳۸ و ۴۳–۴۴؛ ایرادات ۱۱، بندهای ۵.۳
 * تا ۵.۸).
 *
 * حسابِ `E2eSeeder::CHOOSERS` در «آفتاب» مستأجر و در «مجتمع دوم سرتاسری» مدیر است. سفر: ورود ← صفحه‌ی انتخاب (بی
 * مجتمعِ خودکار و بی «ایجادِ حسابِ جدید») ← آفتاب (نوارِ بالا نامش را می‌گوید، منوی آدمک آفتاب را در «مجتمع‌های دیگر»
 * ندارد) ← «ایجادِ حسابِ جدید» ← داشبوردِ اولیه با همان حساب (بی همان گزینه، با هر دو مجتمع) ← بازگشتِ بی خروج به مجتمعِ
 * دوم (مدیر) ← ورودِ دوباره، دوباره صفحه‌ی انتخاب.
 *
 * پیش‌نیاز: `DemoSeeder`، `E2eSeeder`.
 */

const CHOOSERS: Record<string, string> = {
  chromium: '09120000111',
  firefox: '09120000112',
  webkit: '09120000113',
}
const AFTAB = 'مجتمع مسکونی آفتاب'
const SECOND = 'مجتمع دوم سرتاسری'

async function signIn(page: Page, phone: string) {
  await page.context().clearCookies()
  await page.goto('/auth')
  const status = await page.evaluate(async (phone) => {
    await fetch('/api/csrf-token', { credentials: 'include' })
    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '')
    // ورودِ بی‌رمز (۲۰۲۶-۱۰-۳۰): شماره، سپس کدِ پیامکی (با درایورِ log کد در همان پاسخ است)؛
    // سوپر ادمینِ دمو از درِ مدیران با نام کاربری و رمز
    const send = (url: string, data: object) =>
      fetch(url, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-XSRF-TOKEN': xsrf,
        },
        body: JSON.stringify(data),
      })
    const res =
      phone === '09120000001'
        ? await send('/api/admin/login', { username: 'sakena.admin', password: 'password' })
        : await send('/api/login', { phone: phone }).then(async (r) =>
            r.ok ? send('/api/login/verify', { code: (await r.json()).dev_code }) : r,
          )
    return res.status
  }, phone)
  expect(status, `ورودِ ${phone}`).toBe(200)
}

async function me(page: Page) {
  return page.evaluate(async () => {
    const res = await fetch('/api/v1/me', {
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })
    const json = (await res.json()) as {
      user: { role: string; dashboard: string; complex: { name: string } | null }
    }
    return { ...json.user, context: res.headers.get('X-Sakena-Context') }
  })
}

async function openUserMenu(page: Page) {
  await page
    .getByRole('button', { name: /انتخاب‌کننده/ })
    .first()
    .click()
}

test.describe.configure({ mode: 'serial' })

test('ورود ← انتخاب ← «ایجادِ حسابِ جدید» ← بازگشتِ بی خروج', async ({ page }, testInfo) => {
  test.setTimeout(90_000)
  const phone = CHOOSERS[testInfo.project.name]
  test.skip(phone === undefined, 'حسابِ انتخاب فقط برای سه موتورِ دسکتاپ ساخته شده است.')
  const errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))

  // ── ورود: صفحه‌ی انتخاب، هر نشانی‌ای؛ هیچ مجتمعی خودکار برگزیده نشده ──
  await signIn(page, phone)
  await page.goto('/units')
  await expect(page.getByRole('heading', { name: 'انتخاب مجتمع' })).toBeVisible()
  const cards = page.getByRole('list', { name: 'مجتمع‌های شما' })
  await expect(cards.getByRole('button')).toHaveCount(2)
  await expect(page.getByText('ایجاد حساب جدید')).toHaveCount(0)
  expect(await me(page)).toMatchObject({ dashboard: 'select', complex: null, context: '0:choose' })

  // ── آفتاب: مستأجر؛ نامِ مجتمع بالای نوار؛ آفتاب در «مجتمع‌های دیگر» نیست ──
  await Promise.all([
    page.waitForURL(/\/dashboard$/, { waitUntil: 'load' }),
    cards.getByText(AFTAB).click(),
  ])
  await expect(page.getByTestId('active-community')).toHaveText(AFTAB)
  expect(await me(page)).toMatchObject({ role: 'tenant', dashboard: 'unit' })
  const banner = await page.getByRole('note', { name: 'مجتمعِ فعال' }).boundingBox()
  const header = await page.getByRole('banner').boundingBox()
  expect(banner!.y + banner!.height, 'نامِ مجتمع بالای نوارِ اصلی است').toBeLessThanOrEqual(
    header!.y + 1,
  )

  await openUserMenu(page)
  const others = page.getByRole('group', { name: 'مجتمع‌های دیگر' })
  await expect(others).toBeVisible()
  await expect(others.getByText(SECOND)).toBeVisible()
  await expect(others.getByText(AFTAB)).toHaveCount(0)

  // ── «ایجادِ حسابِ جدید» ← همان داشبوردِ اولیه با همان حساب ──
  await Promise.all([
    page.waitForURL(/\/dashboard$/, { waitUntil: 'load' }),
    page.getByRole('button', { name: 'ایجاد حساب جدید' }).click(),
  ])
  await expect(page.getByText('مجتمعِ تازه با همین حساب')).toBeVisible()
  expect(await me(page)).toMatchObject({
    dashboard: 'initial',
    complex: null,
    context: '0:initial',
  })
  await expect(page.getByTestId('active-community')).toHaveCount(0)

  await openUserMenu(page)
  const mine = page.getByRole('group', { name: 'مجتمع‌های من' })
  await expect(mine.getByText(AFTAB)).toBeVisible()
  await expect(mine.getByText(SECOND)).toBeVisible()
  await expect(page.getByRole('button', { name: 'ایجاد حساب جدید' })).toHaveCount(0)

  // ── بازگشت به مجتمعِ دوم بی خروج: مدیر، با نامِ همان‌جا ──
  await Promise.all([
    page.waitForURL(/\/dashboard$/, { waitUntil: 'load' }),
    mine.getByText(SECOND).click(),
  ])
  await expect(page.getByTestId('active-community')).toHaveText(SECOND)
  expect(await me(page)).toMatchObject({ role: 'complex_admin', dashboard: 'manager' })

  /*
   * ── خروج و ورودِ دوباره: دوباره صفحه‌ی انتخاب، نه مجتمعِ دفعه‌ی پیش ──
   * خروجِ واقعی از منوی آدمک، مثلِ کاربر. در اجرای کاملِ محلی روی WebKit، `clearCookies` وسطِ داشبورد با درخواست‌های
   * در جریانِ همان داشبورد مسابقه داد: پاسخِ `web-vitals` کوکیِ نشستِ قبلی را برگرداند، `/auth` به داشبورد رفت، و پس
   * از ورود پاسخِ دیرِ `notifications` (۴۰۱ با کوکیِ نشستِ مهمان) کوکیِ نشستِ تازه را پوشاند (ردِ Playwright).
   */
  await openUserMenu(page)
  await Promise.all([
    page.waitForURL(/\/auth/, { waitUntil: 'load' }),
    page.getByRole('button', { name: 'خروج از حساب' }).click(),
  ])
  await signIn(page, phone)
  await page.goto('/dashboard')
  await expect(page.getByRole('heading', { name: 'انتخاب مجتمع' })).toBeVisible()

  expect(errors, 'خطای JavaScript').toEqual([])
})
