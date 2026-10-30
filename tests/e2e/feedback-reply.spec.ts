import { expect, test, type Page } from '@playwright/test'

/**
 * پاسخ به بازخورد در مرورگرِ واقعی (مرحلهٔ ۸ · فاز ۶ · بندِ ۱۴).
 *
 * بازخورد با شماره‌ی یک عضو ← ادمینِ کل در «بازخورد کاربران» «عضو سایت» می‌بیند
 * و پاسخ می‌دهد ← همان عضو پاسخ را در اعلان‌هایش می‌بیند؛ عضوِ دیگر نه.
 *
 * پیش‌نیاز: `DemoSeeder`. مالکِ واحدِ ۶ و ۵ ِ دمو؛ هر موتور
 * متنِ یکتای خودش را دارد، پس اجراهای پشتِ هم یکدیگر را نمی‌بینند.
 */

const MEMBER = '09121111106'
const OTHER = '09121111105'
const ENGINE: Record<string, string> = { chromium: '1', firefox: '2', webkit: '3' }

async function call(page: Page, method: string, path: string, body?: unknown): Promise<number> {
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
      return res.status
    },
    { method, path, body },
  )
}

async function signIn(page: Page, phone: string) {
  await page.context().clearCookies()
  await page.goto('/auth')
  // ورودِ بی‌رمز (۲۰۲۶-۱۰-۳۰): شماره، سپس کدِ پیامکی (درایورِ log کد را در پاسخ می‌گذارد)؛ سوپر ادمین از درِ مدیران
  const status = await page.evaluate(async (phone) => {
    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '')
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
        : await send('/api/login', { phone }).then(async (r) =>
            r.ok ? send('/api/login/verify', { code: (await r.json()).dev_code }) : r,
          )
    return res.status
  }, phone)
  expect(status, `ورودِ ${phone}`).toBe(200)
}

test('ادمینِ کل به عضو پاسخ می‌دهد و فقط همان عضو آن را در اعلان‌هایش می‌بیند', async ({
  page,
}, testInfo) => {
  const stamp = `${Date.now().toString().slice(-6)}-${ENGINE[testInfo.project.name] ?? '9'}`
  const message = `گزارشِ صدورِ قبض برای آزمونِ پاسخ ${stamp}`
  const answer = `بررسی شد و درست شد — ${stamp}`

  // ── بازخورد با شماره‌ی عضو (مهمان) ──
  await page.goto('/support')
  expect(
    await call(page, 'POST', '/api/v1/feedback', {
      category: 'bug',
      message,
      phone: MEMBER,
      website: '',
    }),
  ).toBe(201)

  // ── ادمینِ کل ──
  await signIn(page, '09120000001')
  await page.goto('/system/feedback')
  await expect(page.getByRole('heading', { level: 1, name: /بازخورد کاربران/ })).toBeVisible()
  const card = page.locator('li').filter({ hasText: message })
  await expect(card).toBeVisible()
  await expect(card.getByText(/عضو سایت · /)).toContainText('با شماره')

  await card.getByRole('textbox').fill(answer)
  await card.getByRole('button', { name: 'ارسالِ پاسخ' }).click()
  await expect(card.getByRole('list', { name: 'پاسخ‌ها' })).toContainText(answer)

  // ── خودِ عضو ──
  await signIn(page, MEMBER)
  await page.goto('/notifications')
  await expect(page.getByText(answer)).toBeVisible()
  await expect(page.getByText('پاسخِ تیمِ ساکنا به بازخوردِ شما').first()).toBeVisible()

  // ── عضوِ دیگر: هیچ ──
  await signIn(page, OTHER)
  await page.goto('/notifications')
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
  await expect(page.getByText(answer)).toHaveCount(0)
})
