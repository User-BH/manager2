import { expect, test, type Page } from '@playwright/test'

/**
 * سفرِ «ثبت‌نام‌کننده‌ی اولیه» (مرحلهٔ ۸ · فاز ۱).
 *
 * ثبت‌نام‌کرده → داشبوردِ جدای خودش → مدیر او را به واحد اضافه می‌کند → دعوت
 * را می‌پذیرد → داشبوردِ واحد. خواستِ کارفرما این است که این حالت فقط یک
 * صفحه‌ی نمایشیِ فرانت نباشد؛ پس هر گام از سرورِ واقعی می‌گذرد: افزودن با همان
 * API ِ فرمِ مدیر (`POST /residents` که برای حسابِ موجود دعوت می‌سازد، نه وصلِ
 * بی‌اجازه)، و پذیرش با کلیک در رابط.
 *
 * 🔴 حساب از `E2eSeeder::REGISTRANTS` می‌آید، نه از API ِ ثبت‌نام: سقفِ
 * `register` پنج در ساعت برای هر IP است، `management-journey` سه تا را
 * می‌خورد و در CI ششمی ۴۲۹ گرفت. فرمِ ثبت‌نام همان‌جا پوشش دارد.
 *
 * ⚠️ پس از پذیرش حساب دیگر اولیه نیست؛ اجرای دوباره‌ی `E2eSeeder` آن را
 * برمی‌گرداند. پیش‌نیاز: `DemoSeeder`، `E2eSeeder`.
 */

/** همان `E2eSeeder::REGISTRANTS` */
const REGISTRANTS: Record<string, string> = {
  chromium: '09120000021',
  firefox: '09120000022',
  webkit: '09120000023',
}
const NOT_A_MEMBER = 'ثبت‌نام شما کامل است؛ هنوز عضو هیچ مجتمعی نیستید'

const phoneFor = (project: string) => REGISTRANTS[project] ?? REGISTRANTS.chromium

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

/** ورودِ بی‌رمز (۲۰۲۶-۱۰-۳۰): شماره، سپس کدِ پیامکی (درایورِ log کد را در پاسخ می‌گذارد) */
async function signIn(page: Page, phone: string) {
  await page.context().clearCookies()
  await page.goto('/auth')
  const start = await call(page, 'POST', '/api/login', { phone })
  expect(start.status, `ورودِ ${phone}`).toBe(200)
  const { status } = await call(page, 'POST', '/api/login/verify', { code: start.json.dev_code })
  expect(status, `کدِ ورودِ ${phone}`).toBe(200)
}

/**
 * «پیوستن» ← کدِ پیامکی ← «تأیید و پیوستن». پذیرشِ دعوت از ۱۲/۳ (بندِ ۱۵) همیشه کد می‌خواهد؛
 * ورود هم کد می‌خواهد (بی‌رمز، ۲۰۲۶-۱۰-۳۰)؛ کد از پاسخِ همان درخواست است (`devCode`، فقط محیطِ محلی با درایورِ log).
 */
async function acceptWithCode(page: Page) {
  const sent = page.waitForResponse(
    (r) =>
      /\/invitations\/\d+\/code$/.test(new URL(r.url()).pathname) &&
      r.request().method() === 'POST',
  )
  await page.getByRole('button', { name: 'پیوستن' }).click()
  const step = (await (await sent).json()) as { otpRequired?: boolean; devCode?: string | null }
  expect(step.otpRequired).toBe(true)
  expect(step.devCode, 'کدِ آزمایشی در این محیط برنگشت').toMatch(/^\d{6}$/)
  await page.getByLabel('کدِ تأیید').fill(step.devCode!)
  await page.getByRole('button', { name: 'تأیید و پیوستن' }).click()
}

test.describe.configure({ mode: 'serial' })

test.describe('ثبت‌نام‌کننده‌ی اولیه', () => {
  test('داشبوردِ جدای خودش را می‌گیرد، نه پوسته‌ی ساکن', async ({ page }, testInfo) => {
    const phone = phoneFor(testInfo.project.name)

    await signIn(page, phone)
    await page.goto('/dashboard')

    await expect(page.getByText(NOT_A_MEMBER)).toBeVisible()
    // تجربه‌ی جدا: منوی خودش، نه منوی ساکن — در همان سایدبارِ سمتِ راستِ بقیه‌ی داشبوردها (۲۰۲۶-۱۰-۲۹، بندِ ۷)
    const menu = page.getByRole('navigation', { name: 'منوی حساب' })
    await expect(menu.getByRole('link')).toHaveText([
      'داشبورد',
      'اعلان‌ها',
      'پروفایل',
      'حساب و اشتراک',
      'کیف پول',
    ])
    await expect(page.locator('aside').filter({ has: menu })).toHaveCount(1)
    await expect(page.locator('a[href="/my-bills"]')).toHaveCount(0)

    /*
     * کیف مالِ حساب است (مرحلهٔ ۹ · اصلاحاتِ پس از فاز ۶): حسابِ اولیه هم — مثلاً
     * ساکنِ رفته — از همین داشبورد به کیفِ خودش می‌رسد.
     */
    await menu.getByRole('link', { name: 'کیف پول' }).click()
    await expect(page).toHaveURL(/\/wallet$/)
    await expect(page.getByRole('heading', { name: 'کیف پول', level: 1 })).toBeVisible()
    // یک کیفِ شخصی با مانده‌ی صفر و دفترِ خالی — نه «کیفِ نداشته»
    await expect(page.getByRole('button', { name: /کیفِ پولِ شخصی/ })).toBeVisible()
    await expect(page.getByText('هنوز تراکنشی ثبت نشده است.')).toBeVisible()
    await page.goto('/dashboard')

    // گوشیِ باریک: همان کشوی منوی بقیه‌ی داشبوردها، هر پنج مقصد دیده‌شدنی و بی سرریزِ افقی (۲۰۲۶-۱۰-۲۹، بندِ ۷)
    for (const width of [320, 360, 430]) {
      await page.setViewportSize({ width, height: 800 })
      await page.getByRole('button', { name: 'باز کردن منو' }).click()
      const drawer = page.getByRole('dialog', { name: 'منوی اصلی' })
      for (const label of ['داشبورد', 'اعلان‌ها', 'پروفایل', 'حساب و اشتراک', 'کیف پول'])
        await expect(drawer.getByRole('link', { name: label })).toBeInViewport()
      await page.keyboard.press('Escape')
      await expect(drawer).toBeHidden()
      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - window.innerWidth,
      )
      expect(overflow, `${width}px: سرریزِ افقی`).toBeLessThanOrEqual(0)
    }
    await page.setViewportSize({ width: 1280, height: 800 })

    // صفحه‌ی ساکن مالِ این حالت نیست
    await page.goto('/my-bills')
    await expect(page).toHaveURL(/\/dashboard$/)
    await expect(page.getByText(NOT_A_MEMBER)).toBeVisible()
  })

  /*
   * نام و نام خانوادگیِ جدا (مرحلهٔ ۹ · فاز ۳): حسابِ قبلی نامش با حدس شکسته
   * نمی‌شود؛ فقط درخواستِ تکمیل می‌بیند و خودش در پروفایل جدا می‌کند.
   */
  test('حسابِ قبلی نامش را خودش جدا می‌کند و درخواستِ تکمیل می‌رود', async ({ page }, testInfo) => {
    await signIn(page, phoneFor(testInfo.project.name))
    await page.goto('/dashboard')

    const prompt = page.getByText('نام و نام خانوادگی‌تان را جدا ثبت کنید.')
    await expect(prompt).toBeVisible()
    await expect(page.getByRole('region', { name: 'راهنما' })).toBeVisible()
    await prompt.click()
    await expect(page).toHaveURL(/\/profile$/)

    await page.getByRole('button', { name: 'ویرایش پروفایل' }).click()
    await expect(
      page.getByRole('status').filter({ hasText: 'ثبت‌نام‌کننده‌ی سرتاسری' }),
    ).toBeVisible()
    await page.getByLabel('نام', { exact: true }).fill('ثبت‌نام‌کننده')
    await page.getByLabel('نام خانوادگی').fill('سرتاسری')
    const saved = page.waitForResponse(
      (r) => new URL(r.url()).pathname.endsWith('/profile') && r.request().method() === 'PUT',
    )
    await page.getByRole('button', { name: /ذخیره تغییرات/ }).click()
    expect((await saved).status()).toBe(200)

    await page.goto('/dashboard')
    await expect(
      page.getByRole('heading', { level: 1, name: 'ثبت‌نام‌کننده سرتاسری، خوش آمدید' }),
    ).toBeVisible()
    await expect(prompt).toHaveCount(0)
  })

  test('مدیر او را به واحد اضافه می‌کند؛ با پذیرش، داشبوردِ واحد باز می‌شود', async ({
    page,
  }, testInfo) => {
    const phone = phoneFor(testInfo.project.name)

    // ── مدیرِ مجتمعِ آفتاب، با همان API ِ فرمِ «ساکن جدید» ──
    await signIn(page, '09120000000')
    const units = await call(page, 'GET', '/api/v1/units?per_page=50')
    const list = (units.json.data ?? []) as { id: number; unitNumber: string }[]
    const unit = list.find((u) => u.unitNumber === '5')
    expect(unit, 'واحدِ ۵ ِ دمو پیدا نشد').toBeTruthy()

    // مرحلهٔ ۹ · فاز ۶: فقط شماره، نقش و واحد — نه نام و نه رمز؛ دعوت، نه وصلِ بی‌اجازه
    const added = await call(page, 'POST', '/api/v1/residents', {
      phone,
      role: 'tenant',
      unit_id: unit!.id,
    })
    expect(added.status, JSON.stringify(added.json)).toBe(201)
    expect(added.json.invited).toBe(true)

    // ── خودِ ثبت‌نام‌کننده ──
    await signIn(page, phone)
    await page.goto('/dashboard')
    await expect(page.getByText(NOT_A_MEMBER)).toBeVisible()
    await expect(page.getByText('مجتمع مسکونی آفتاب')).toBeVisible()

    // بندِ ۳۴ ِ فایل ۲: پس از گذار همان حساب با همان پروفایل — نه حسابِ تازه
    const identity = async () => {
      const { json } = await call(page, 'GET', '/api/me')
      const u = json.user as Record<string, unknown>
      return [u.id, u.name, u.firstName, u.lastName, u.phone]
    }
    const before = await identity()
    expect(before[4]).toBe(phone)

    await acceptWithCode(page)

    // حالا عضو است: پوسته‌ی واحد با سایدبار، و صفحه‌های ساکن باز
    await expect(page.locator('aside').first()).toBeAttached({ timeout: 15_000 })
    await expect(page.getByText(NOT_A_MEMBER)).toHaveCount(0)
    await expect(page.locator('[data-experience="registrant"]')).toHaveCount(0)

    await page.goto('/my-bills')
    await expect(page).toHaveURL(/\/my-bills$/)

    expect(await identity(), 'گذار به واحد حساب یا پروفایل را عوض کرد').toEqual(before)
  })
})
