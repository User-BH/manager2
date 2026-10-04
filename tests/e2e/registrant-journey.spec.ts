import { expect, test, type Page } from '@playwright/test'

/**
 * سفرِ «ثبت‌نام‌کننده‌ی اولیه» (مرحلهٔ ۸ · فاز ۱).
 *
 * ثبت‌نام → داشبوردِ جدای خودش → مدیر او را به واحد اضافه می‌کند → دعوت را
 * می‌پذیرد → داشبوردِ واحد. خواستِ کارفرما این است که این حالت فقط یک صفحه‌ی
 * نمایشیِ فرانت نباشد؛ پس هر گام از سرورِ واقعی می‌گذرد: ثبت‌نام با کد،
 * افزودن با همان API ِ فرمِ مدیر (`POST /residents` که برای حسابِ موجود دعوت
 * می‌سازد، نه وصلِ بی‌اجازه)، و پذیرش با کلیک در رابط.
 *
 * ثبت‌نام این‌جا با API است: فرمش در `management-journey` با کلیک و تایپ
 * پوشش دارد.
 *
 * ⚠️ هر موتور و هر اجرا شماره‌ی خودش را دارد: پس از پذیرش، حساب دیگر اولیه
 * نیست و اجرای دوباره روی همان پایگاه‌داده شماره‌ی تازه می‌خواهد.
 * پیش‌نیاز: `DemoSeeder`، `otp_disabled`، `APP_ENV=local`، `SMS_DRIVER=log`.
 */

const ENGINE: Record<string, string> = { chromium: '1', firefox: '2', webkit: '3' }
const RUN = String(Date.now()).slice(-6)
const PASSWORD = 'Secret123x'
const NOT_A_MEMBER = 'ثبت‌نام شما کامل است؛ هنوز عضو هیچ مجتمعی نیستید'

const phoneFor = (project: string) => `0937${RUN}${ENGINE[project] ?? '9'}`

async function call(
  page: Page,
  method: string,
  path: string,
  body?: unknown,
): Promise<{ status: number; json: Record<string, unknown> }> {
  return page.evaluate(
    async ({ method, path, body }) => {
      await fetch('/api/csrf-token', { credentials: 'include' })
      const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '')
      const res = await fetch(path, {
        method,
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-XSRF-TOKEN': xsrf,
        },
        body: body === undefined ? undefined : JSON.stringify(body),
      })
      return {
        status: res.status,
        json: (await res.json().catch(() => ({}))) as Record<string, unknown>,
      }
    },
    { method, path, body },
  )
}

async function signIn(page: Page, phone: string, password: string) {
  await page.context().clearCookies()
  await page.goto('/auth')
  const { status } = await call(page, 'POST', '/api/login', { phone, password })
  expect(status, `ورودِ ${phone}`).toBe(200)
}

test.describe.configure({ mode: 'serial' })

test.describe('ثبت‌نام‌کننده‌ی اولیه', () => {
  test('ثبت‌نام می‌کند و داشبوردِ جدای خودش را می‌گیرد', async ({ page }, testInfo) => {
    const phone = phoneFor(testInfo.project.name)

    await page.goto('/auth')
    const sent = await call(page, 'POST', '/api/register', {
      name: 'ثبت‌نام‌کننده‌ی سرتاسری',
      phone,
      password: PASSWORD,
      password_confirmation: PASSWORD,
      accept_terms: true,
    })
    expect(sent.status, JSON.stringify(sent.json)).toBe(200)
    expect(sent.json.dev_code, 'کدِ آزمایشی نیامد؛ APP_ENV=local و SMS_DRIVER=log').toBeTruthy()
    const verified = await call(page, 'POST', '/api/register/verify', { code: sent.json.dev_code })
    expect(verified.status).toBeLessThan(300)

    await signIn(page, phone, PASSWORD)
    await page.goto('/dashboard')

    await expect(page.getByText(NOT_A_MEMBER)).toBeVisible()
    // تجربه‌ی جدا: منوی خودش، نه سایدبارِ ساکن
    const menu = page.getByRole('navigation', { name: 'منوی حساب' })
    await expect(menu.getByRole('link')).toHaveText([
      'داشبورد',
      'اعلان‌ها',
      'پروفایل',
      'حساب و اشتراک',
    ])
    await expect(page.locator('aside')).toHaveCount(0)

    // هر چهار زبانه در گوشیِ باریک هم بی‌اسکرولِ پنهان جا می‌شوند
    for (const width of [360, 430]) {
      await page.setViewportSize({ width, height: 800 })
      const fit = await menu.locator('ul').evaluate((ul) => ul.scrollWidth - ul.clientWidth)
      expect(fit, `${width}px: زبانه‌ای پشتِ اسکرول است`).toBeLessThanOrEqual(1)
    }

    // صفحه‌ی ساکن مالِ این حالت نیست
    await page.goto('/my-bills')
    await expect(page).toHaveURL(/\/dashboard$/)
    await expect(page.getByText(NOT_A_MEMBER)).toBeVisible()
  })

  test('مدیر او را به واحد اضافه می‌کند؛ با پذیرش، داشبوردِ واحد باز می‌شود', async ({
    page,
  }, testInfo) => {
    const phone = phoneFor(testInfo.project.name)

    // ── مدیرِ مجتمعِ آفتاب، با همان API ِ فرمِ «ساکن جدید» ──
    await signIn(page, '09120000000', 'password')
    const units = await call(page, 'GET', '/api/v1/units?per_page=50')
    const list = (units.json.data ?? []) as { id: number; unitNumber: string }[]
    const unit = list.find((u) => u.unitNumber === '5')
    expect(unit, 'واحدِ ۵ ِ دمو پیدا نشد').toBeTruthy()

    const added = await call(page, 'POST', '/api/v1/residents', {
      name: 'ثبت‌نام‌کننده‌ی سرتاسری',
      phone,
      role: 'tenant',
      unit_id: unit!.id,
      password: PASSWORD,
    })
    // حسابِ موجود دعوت می‌گیرد (۲۰۲)، نه وصلِ بی‌اجازه
    expect(added.status, JSON.stringify(added.json)).toBe(202)

    // ── خودِ ثبت‌نام‌کننده ──
    await signIn(page, phone, PASSWORD)
    await page.goto('/dashboard')
    await expect(page.getByText(NOT_A_MEMBER)).toBeVisible()
    await expect(page.getByText('مجتمع مسکونی آفتاب')).toBeVisible()

    await page.getByRole('button', { name: 'پیوستن' }).click()

    // حالا عضو است: پوسته‌ی واحد با سایدبار، و صفحه‌های ساکن باز
    await expect(page.locator('aside').first()).toBeAttached({ timeout: 15_000 })
    await expect(page.getByText(NOT_A_MEMBER)).toHaveCount(0)
    await expect(page.locator('[data-experience="registrant"]')).toHaveCount(0)

    await page.goto('/my-bills')
    await expect(page).toHaveURL(/\/my-bills$/)
  })
})
