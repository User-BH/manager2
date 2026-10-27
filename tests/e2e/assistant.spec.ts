import { expect, test, type Page } from '@playwright/test'

/**
 * دستیارِ داخلی در مرورگرِ واقعی — مرحلهٔ ۱۳ · فاز ۸ (ایرادات ۱۱، بندهای ۴.۲، ۴.۳، ۴.۱۵ و ۵.۱۸؛ آزمونِ ۴۴).
 *
 * حسابِ `E2eSeeder::ASSISTANT` مستأجرِ «آفتاب» (بی پلنِ پیشرفته) و مدیرِ «مجتمع دستیار سرتاسری» (پلنِ پیشرفته) است.
 * سفر: آفتاب بی دکمه ← تعویض ← دکمه‌ی دایره‌ایِ پایینِ چپ ← پنل ← پرسشِ صندوق ← پاسخ از داده‌ی واقعیِ همان مجتمع ←
 * بازگشت به آفتاب: دکمه نیست و API ۴۰۳. و در ۳۷۵: دکمه و پنل بی سرریزِ افقی.
 *
 * پیش‌نیاز: `DemoSeeder`، `E2eSeeder` و `otp_disabled`.
 */

const ACCOUNTS: Record<string, string> = {
  chromium: '09120000141',
  firefox: '09120000142',
  webkit: '09120000143',
}
const ASSISTANT_COMPLEX = 'مجتمع دستیار سرتاسری'
const LAUNCHER = 'دستیارِ هوشمندِ ساکنا'

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
    ).json()) as { user: { memberships: { complexId: number; complexName: string }[] } }
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

async function historyStatus(page: Page) {
  return page.evaluate(async () => {
    const res = await fetch('/api/v1/assistant/history', {
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })
    return res.status
  })
}

test.describe.configure({ mode: 'serial' })

test.describe('دستیارِ داخلی', () => {
  test('فقط در مجتمعِ پلنِ پیشرفته؛ پاسخ از داده‌ی واقعیِ همان مجتمع', async ({
    page,
  }, testInfo) => {
    await signIn(page, ACCOUNTS[testInfo.project.name] ?? ACCOUNTS.chromium)
    await enterAftab(page)

    // آفتاب پلنِ پیشرفته ندارد: نه دکمه، نه API
    await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible()
    await expect(page.getByRole('button', { name: LAUNCHER })).toHaveCount(0)
    expect(await historyStatus(page)).toBe(403)

    await switchTo(page, ASSISTANT_COMPLEX)
    await page.goto('/dashboard')
    const launcher = page.getByRole('button', { name: LAUNCHER })
    await expect(launcher).toBeVisible()

    // دایره‌ای، پایینِ چپ، بیرون از منوی کناریِ سمتِ راست (بندِ ۴.۳)
    const box = (await launcher.boundingBox())!
    const viewport = page.viewportSize()!
    expect(box.x, 'دکمه سمتِ چپ نیست').toBeLessThan(viewport.width / 3)
    expect(box.y + box.height, 'دکمه پایینِ صفحه نیست').toBeGreaterThan(viewport.height - 120)
    expect(Math.abs(box.width - box.height)).toBeLessThan(2)

    await launcher.click()
    const dialog = page.getByRole('dialog', { name: LAUNCHER })
    await expect(dialog.getByText(ASSISTANT_COMPLEX)).toBeVisible()
    await dialog.getByLabel('پرسش از دستیار').fill('هزینه‌های این ماه چقدر بوده است؟')
    await dialog.getByLabel('پرسش از دستیار').press('Enter')

    await expect(dialog.getByText('۴۲۰,۰۰۰ تومان').first()).toBeVisible()
    await expect(dialog.getByText('هزینه‌ی آزمونِ دستیار')).toBeVisible()
    await expect(dialog.getByText(/^مبنا:/)).toBeVisible()

    // گفت‌وگو با بستن و بازکردن می‌ماند
    await page.keyboard.press('Escape')
    await expect(dialog).toHaveCount(0)
    await launcher.click()
    await expect(
      page.getByRole('dialog').getByText('هزینه‌های این ماه چقدر بوده است؟'),
    ).toBeVisible()

    // در ۳۷۵ پیکسل همان پنلِ باز: بی سرریزِ افقی و درونِ صفحه — در همین سفر، تا هر موتور یک بار وارد شود
    await page.setViewportSize({ width: 375, height: 812 })
    const mobile = page.getByRole('dialog', { name: LAUNCHER })
    await expect(mobile.getByLabel('پرسش از دستیار')).toBeVisible()
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )
    expect(overflow, 'پنل صفحه را افقی سرریز کرد').toBeLessThanOrEqual(0)
    const panel = (await mobile.boundingBox())!
    expect(panel.x).toBeGreaterThanOrEqual(0)
    expect(panel.x + panel.width).toBeLessThanOrEqual(375)

    // بندِ ۵.۱۸ و آزمونِ ۴۴: بازگشت به آفتاب — دکمه نیست و گفت‌وگوی مجتمعِ دیگر هم نه
    await switchTo(page, 'مجتمع مسکونی آفتاب')
    await page.goto('/dashboard')
    await expect(page.getByRole('banner')).toBeVisible()
    await expect(page.getByRole('button', { name: LAUNCHER })).toHaveCount(0)
    expect(await historyStatus(page)).toBe(403)
  })
})
