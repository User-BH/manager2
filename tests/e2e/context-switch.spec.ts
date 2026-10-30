import { expect, test, type Page } from '@playwright/test'

/**
 * تعویضِ مجتمع در مرورگرِ واقعی — مرحلهٔ ۱۰ · فاز ۴ (بندهای ۱۳، ۱۴، ۸۵، ۹۲ و ۱۲۲ ِ فایلِ معماری).
 *
 * حسابِ `E2eSeeder::MULTI` در «آفتاب» مستأجر و در «مجتمع دوم سرتاسری» مدیر است؛ زمینه‌ی
 * شروع آفتاب. سفر: آفتاب (مستأجر، بی مسیرِ مدیریت) ← تعویض از منوی کاربر ← مجتمعِ دوم
 * (مدیر، فقط واحدهای همان‌جا) ← برگشت. و تبِ دومِ همان حساب با تعویض خودش از نو بار
 * می‌شود: دادهٔ مجتمعِ قبلی در هیچ تبی نمی‌ماند.
 *
 * پیش‌نیاز: `DemoSeeder` و `E2eSeeder`. سفر زمینه را به آفتاب برمی‌گرداند تا تکرارپذیر باشد.
 */

/** همان `E2eSeeder::MULTI` — یکی برای هر موتور */
const MULTI: Record<string, string> = {
  chromium: '09120000061',
  firefox: '09120000062',
  webkit: '09120000063',
}
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
    const res = await fetch('/api/me', {
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })
    return ((await res.json()) as { user: { role: string; complex: { name: string } | null } }).user
  })
}

async function unitNumbers(page: Page): Promise<{ status: number; numbers: string[] }> {
  return page.evaluate(async () => {
    const res = await fetch('/api/v1/units?per_page=100', {
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })
    const json = (await res.json().catch(() => ({}))) as { data?: { unitNumber: string }[] }
    return { status: res.status, numbers: (json.data ?? []).map((u) => u.unitNumber) }
  })
}

/**
 * ورودِ حسابِ چندمجتمعی به صفحه‌ی انتخاب است (مرحلهٔ ۱۳ · فاز ۳) — هیچ مجتمعی خودکار برگزیده نمی‌شود. از صفحه‌ای جز
 * داشبورد، تا رسیدن به `/dashboard` همان ناوبریِ کاملِ تعویض باشد.
 */
async function chooseOnSelection(page: Page, complexName: string) {
  await page.goto('/profile')
  await expect(page.getByRole('heading', { name: 'انتخاب مجتمع' })).toBeVisible()
  await Promise.all([
    page.waitForURL(/\/dashboard$/, { waitUntil: 'load' }),
    page.getByRole('list', { name: 'مجتمع‌های شما' }).getByText(complexName).click(),
  ])
}

async function switchFromMenu(page: Page, complexName: string) {
  // از صفحه‌ای جز داشبورد، تا رسیدن به `/dashboard` همان ناوبریِ کاملِ تعویض باشد
  await page.goto('/profile')
  await page
    .getByRole('button', { name: /چندمجتمعی/ })
    .first()
    .click()
  // مجتمعِ همین زمینه در فهرست نیست (۱۳/۳)
  const group = page.getByRole('group', { name: 'مجتمع‌های دیگر' })
  await expect(group).toBeVisible()
  await Promise.all([
    page.waitForURL(/\/dashboard$/, { waitUntil: 'load' }),
    group.getByText(complexName).click(),
  ])
}

test.describe.configure({ mode: 'serial' })

test.describe('تعویضِ مجتمع', () => {
  test('نقش، داده و مسیرها با مجتمعِ فعال عوض می‌شوند؛ تبِ دوم هم', async ({ page }, testInfo) => {
    const phone = MULTI[testInfo.project.name] ?? MULTI.chromium
    await signIn(page, phone)
    await chooseOnSelection(page, 'مجتمع مسکونی آفتاب')

    // آفتاب: مستأجر — مسیرِ مدیریت بسته، سرور هم رد می‌کند
    expect((await me(page)).role).toBe('tenant')
    expect((await unitNumbers(page)).status).toBe(403)
    await page.goto('/units')
    await expect(page).toHaveURL(/\/forbidden$/)

    // تبِ دوم از همین حساب، روی داشبورد
    const other = await page.context().newPage()
    await other.goto('/dashboard')
    await expect(other.getByRole('button', { name: /چندمجتمعی/ }).first()).toBeVisible()
    const otherReloaded = other.waitForEvent('load')

    // تعویض به مجتمعِ دوم از منوی کاربر
    await switchFromMenu(page, SECOND)

    const managed = await me(page)
    expect([managed.role, managed.complex?.name]).toEqual(['complex_admin', SECOND])
    const units = await unitNumbers(page)
    expect(units.status).toBe(200)
    expect(units.numbers).toEqual(['دوم-۱'])
    await page.goto('/units')
    await expect(page).toHaveURL(/\/units$/)
    await expect(page.getByText('دوم-۱').first()).toBeVisible()

    // تبِ دوم خودش از نو بار شد و حالا مجتمعِ دوم را می‌بیند
    await otherReloaded
    expect((await me(other)).complex?.name).toBe(SECOND)
    await other.close()

    // برگشت به آفتاب: دوباره مستأجر، و دادهٔ مجتمعِ دوم دیگر در دسترس نیست
    await switchFromMenu(page, 'مجتمع مسکونی آفتاب')
    expect((await me(page)).role).toBe('tenant')
    expect((await unitNumbers(page)).status).toBe(403)
  })
})
