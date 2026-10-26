import { expect, test, type Page } from '@playwright/test'

/**
 * اعلانِ دعوت در مرورگرِ واقعی — مرحلهٔ ۱۳ · فاز ۵ (ایرادات ۱۱، بندهای ۳.۴ و ۵.۱۳؛ آزمونِ ۱۴ ِ بندِ ۳.۱۲).
 *
 * حسابِ `E2eSeeder::INVITEES` مستأجرِ «آفتاب» است و مدیرِ «مجتمع دوم سرتاسری» دعوتش کرده (همان `Membership::invite`).
 * سفر: داشبوردِ آفتاب با نشانِ روی آدمک ← «دعوت‌ها» ← داشبوردِ اولیه، صفحه‌ی دعوت‌ها با جزئیاتِ واقعی ← اعلان در
 * زنگوله‌ی همان‌جا، باز کردنش نشان را برمی‌دارد ولی عضویت نمی‌سازد ← رد ← حالتِ خالی. در ۱۲۸۰ و ۳۷۵.
 *
 * پیش‌نیاز: `DemoSeeder`، `E2eSeeder` و `otp_disabled`.
 */

const INVITEES: Record<string, string> = {
  chromium: '09120000127',
  firefox: '09120000128',
  webkit: '09120000129',
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

async function me(page: Page) {
  return page.evaluate(async () => {
    const res = await fetch('/api/v1/me', {
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })
    return (
      (await res.json()) as {
        user: {
          dashboard: string
          unreadInvitations: number
          pendingInvitations: number
          memberships: unknown[]
        }
      }
    ).user
  })
}

test.describe.configure({ mode: 'serial' })

test('نشانِ دعوت ← داشبوردِ اولیه ← جزئیات ← خواندن ≠ عضویت ← رد', async ({ page }, testInfo) => {
  test.setTimeout(90_000)
  const phone = INVITEES[testInfo.project.name]
  test.skip(phone === undefined, 'حسابِ دعوت‌شده فقط برای سه موتورِ دسکتاپ ساخته شده است.')
  const errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))

  await signIn(page, phone)
  await page.goto('/dashboard')
  const account = page.getByRole('banner').getByRole('button', { name: /^حساب کاربری/ })

  // ── داشبوردِ آفتاب: نشان روی آدمک از عددِ سرور، در دو عرض ──
  for (const width of [1280, 375]) {
    await page.setViewportSize({ width, height: 800 })
    await expect(account).toHaveAccessibleName(/۱ دعوتِ تازه$/)
    const badge = account.getByTestId('invitation-badge')
    await expect(badge).toHaveText('۱')
    const [b, a] = [(await badge.boundingBox())!, (await account.boundingBox())!]
    expect(b.x + b.width / 2, `${width}px: نشان روی دکمه‌ی آدمک`).toBeGreaterThan(a.x)
    expect(b.y + b.height / 2, `${width}px: نشان روی دکمه‌ی آدمک`).toBeLessThan(a.y + a.height)
  }
  await page.setViewportSize({ width: 1280, height: 800 })

  // ── «دعوت‌ها» ← داشبوردِ اولیه، صفحه‌ی دعوت‌ها با جزئیاتِ واقعی ──
  await account.click()
  await Promise.all([
    page.waitForURL(/\/invitations$/, { waitUntil: 'load' }),
    page.getByRole('button', { name: 'دعوت‌ها (1)' }).click(),
  ])
  await expect(page.getByRole('heading', { name: 'دعوت‌ها' })).toBeVisible()
  await expect(page.getByText(SECOND)).toBeVisible()
  await expect(page.getByText(/به عنوان مدیر مجتمع/)).toBeVisible()
  expect(await me(page)).toMatchObject({ dashboard: 'initial', unreadInvitations: 1 })

  // ── زنگوله‌ی داشبوردِ اولیه: همان اعلان؛ باز کردنش خواندن است، نه عضویت ──
  // کلیک، مثلِ کاربر: پیش از ۱۳/۵ کلیکِ پس از hover همان باکسِ تازه‌بازشده را می‌بست (`NotificationBell`)
  await page.getByRole('banner').getByRole('button', { name: /اعلان/ }).first().click()
  await page.getByText(`دعوت به «${SECOND}»`).first().click()
  await expect(account.getByTestId('invitation-badge')).toHaveCount(0)
  const afterRead = await me(page)
  expect([
    afterRead.unreadInvitations,
    afterRead.pendingInvitations,
    afterRead.memberships.length,
  ]).toEqual([0, 1, 1])

  // ── رد ← حالتِ خالی؛ عضویتی ساخته نشد ──
  await page.getByRole('button', { name: /^رد$/ }).click()
  await expect(page.getByText('دعوتِ در انتظاری ندارید.')).toBeVisible()
  const done = await me(page)
  expect([done.pendingInvitations, done.memberships.length]).toEqual([0, 1])

  expect(errors, 'خطای JavaScript').toEqual([])
})
