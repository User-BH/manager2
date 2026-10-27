import { expect, test, type Page } from '@playwright/test'

/**
 * تقویم و یادآور در مرورگرِ واقعی — مرحلهٔ ۱۳ · فاز ۷ (ایرادات ۱۰، بندهای ۶۷، ۷۰، ۷۷ تا ۸۰، ۸۸، ۹۶ و ۱۰۲).
 *
 * حسابِ `E2eSeeder::CALENDAR` مستأجرِ «آفتاب» و مدیرِ «مجتمع دوم سرتاسری» است (seeder یادآورهایش را پاک می‌کند).
 * سفر: آیکونِ همیشگیِ تقویم در نوار ← تقویمِ شمسی در محتوای اصلی ← روزی در ماهِ بعد ← فرم ← پیش‌نمایش ← ثبت ←
 * نشانگرِ روز و تاریخچه ← تعویض به مجتمعِ دوم: یادآورِ آفتاب نیست (بندِ ۱۰۲). و در ۳۷۵: بی سرریزِ افقی.
 *
 * پیش‌نیاز: `DemoSeeder`، `E2eSeeder` و `otp_disabled`.
 */

const ACCOUNTS: Record<string, string> = {
  chromium: '09120000131',
  firefox: '09120000132',
  webkit: '09120000133',
}
const SECOND = 'مجتمع دوم سرتاسری'

async function signIn(page: Page, phone: string) {
  await page.goto('/auth')
  const status = await page.evaluate(async (phone) => {
    await fetch('/api/csrf-token', { credentials: 'include' })
    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '')
    const res = await fetch('/api/login', {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-XSRF-TOKEN': xsrf,
      },
      body: JSON.stringify({ phone, password: 'password' }),
    })
    return res.status
  }, phone)
  expect(status, `ورودِ ${phone}`).toBe(200)
}

/** حسابِ چندمجتمعی پس از ورود صفحه‌ی انتخاب می‌بیند (۱۳/۳)؛ «آفتاب» را برمی‌گزیند */
async function enterAftab(page: Page) {
  await page.goto('/dashboard')
  await page.getByRole('button', { name: /^مجتمع مسکونی آفتاب/ }).click()
  await expect(page.getByRole('banner')).toBeVisible()
}

/** تعویضِ زمینه از همان مسیرِ واقعیِ سرور (`POST account/context`) */
async function switchTo(page: Page, complexName: string) {
  const status = await page.evaluate(async (name) => {
    const me = (await (
      await fetch('/api/v1/me', { credentials: 'include', headers: { Accept: 'application/json' } })
    ).json()) as {
      user: { memberships: { complexId: number; complexName: string }[] }
    }
    const target = me.user.memberships.find((m) => m.complexName === name)
    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '')
    const res = await fetch('/api/v1/account/context', {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-XSRF-TOKEN': xsrf,
      },
      body: JSON.stringify({ complex_id: target?.complexId }),
    })
    return res.status
  }, complexName)
  expect(status, `تعویض به ${complexName}`).toBe(200)
}

test.describe.configure({ mode: 'serial' })

test.describe('تقویم و یادآور', () => {
  test('یادآور از تقویمِ شمسی ساخته می‌شود و فقط در همان مجتمع دیده می‌شود', async ({
    page,
  }, testInfo) => {
    const title = `یادآور سرتاسری ${testInfo.project.name}`
    await signIn(page, ACCOUNTS[testInfo.project.name] ?? ACCOUNTS.chromium)
    await enterAftab(page)

    // بندِ ۶۷: آیکونِ همیشگی در نوار؛ تقویم در محتوای اصلی
    await page.getByRole('banner').getByRole('link', { name: 'تقویم' }).click()
    await expect(page).toHaveURL(/\/calendar$/)
    const grid = page.getByRole('grid')
    await expect(grid.locator('[aria-current="date"]')).toHaveCount(1)

    // روزِ دهمِ ماهِ بعد — همیشه آینده
    await page.getByRole('button', { name: 'ماه بعد' }).click()
    await grid.getByRole('gridcell', { name: /^۱۰ / }).click()
    await page.getByRole('button', { name: 'یادآورِ تازه برای این روز' }).click()
    await page.getByLabel('موضوع').fill(title)
    await page.getByLabel('زمانِ یادآوری').selectOption('3')
    await page.getByRole('button', { name: 'پیش‌نمایش' }).click()
    await expect(page.getByText('۳ روز قبل')).toBeVisible()
    await page.getByRole('button', { name: 'ثبت یادآور' }).click()

    // نشانگرِ روز و فهرستِ همان روز
    await expect(grid.getByRole('gridcell', { name: /^۱۰ .*، ۱ یادآور/ })).toBeVisible()
    await expect(page.getByRole('button', { name: new RegExp(title) })).toBeVisible()

    await page.getByRole('link', { name: 'تاریخچه یادآورها' }).click()
    await expect(page.getByText(title)).toBeVisible()

    // بندِ ۱۰۲: مجتمعِ دیگرِ همان حساب یادآورِ آفتاب را ندارد
    await switchTo(page, SECOND)
    await page.goto('/calendar/history')
    await expect(page.getByRole('heading', { level: 1, name: 'تاریخچه یادآورها' })).toBeVisible()
    await expect(page.getByText('هنوز یادآوری ندارید')).toBeVisible()
    await expect(page.getByText(title)).toHaveCount(0)
  })

  test('در ۳۷۵ پیکسل: تقویم بی سرریزِ افقی و روزها قابلِ لمس', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 375, height: 812 })
    await signIn(page, ACCOUNTS[testInfo.project.name] ?? ACCOUNTS.chromium)
    await enterAftab(page)
    await page.goto('/calendar')
    await expect(page.getByRole('grid')).toBeVisible()

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )
    expect(overflow, 'تقویم صفحه را افقی سرریز کرد').toBeLessThanOrEqual(0)
    const box = await page.getByRole('grid').locator('[aria-current="date"]').boundingBox()
    expect(box?.width ?? 0, 'خانه‌ی روز برای لمس کوچک است').toBeGreaterThanOrEqual(36)
    await expect(page.getByRole('banner').getByRole('link', { name: 'تقویم' })).toBeVisible()
  })
})
