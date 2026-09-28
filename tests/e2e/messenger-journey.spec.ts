import { expect, test, type Browser, type Page } from '@playwright/test'

/**
 * پیام‌رسانِ گفت‌وگومحور در مرورگرِ واقعی (مرحلهٔ ۶ · فاز ۱۲).
 *
 * ساکن به مدیریت می‌نویسد؛ مدیر گفت‌وگوی آن واحد را با شمارِ نخوانده می‌بیند
 * و پاسخ می‌دهد؛ ساکن پاسخ را — بدونِ بارگذاریِ دوباره — می‌گیرد و پیامِ خودش
 * «خوانده شد» می‌شود.
 *
 * ⚠️ بی‌درنگ نیست (تصمیمِ ۲): پاسخ با پرسشِ دوره‌ای (۸ ثانیه) می‌رسد.
 *
 * پیش‌نیاز: `DemoSeeder` و `otp_disabled`.
 */

// مالک‌هایی که سفرِ مالی و امنیت به کار نمی‌برند
const RESIDENTS: Record<string, string> = {
  chromium: '09121111101',
  firefox: '09121111105',
  webkit: '09121111106',
}

const MANAGER = '09120000000'
const RUN = String(Date.now()).slice(-6)

async function signedIn(browser: Browser, phone: string): Promise<Page> {
  const page = await (await browser.newContext()).newPage()
  await page.goto('/auth')

  const status = await page.evaluate(
    async (creds) => {
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
        body: JSON.stringify(creds),
      })
      return res.status
    },
    { phone, password: 'password' },
  )

  expect(status).toBe(200)
  return page
}

test('ساکن می‌نویسد، مدیر پاسخ می‌دهد، ساکن «خوانده شد» می‌بیند', async ({ browser }, testInfo) => {
  test.setTimeout(90_000)

  const phone = RESIDENTS[testInfo.project.name] ?? '09121111101'
  const question = `آبِ گرم قطع است ${RUN}-${testInfo.project.name}`
  const answer = `تعمیرکار فردا می‌آید ${RUN}-${testInfo.project.name}`

  // ── ساکن ──
  const resident = await signedIn(browser, phone)
  await resident.goto('/messenger')
  await resident
    .getByRole('navigation', { name: 'گفت‌وگوها' })
    .getByRole('button', { name: /مدیریتِ ساختمان/ })
    .click()

  await resident.getByLabel('متنِ پیام').fill(question)
  await resident.getByRole('button', { name: 'ارسال پیام' }).click()

  const mine = resident
    .getByRole('list', { name: 'پیام‌ها' })
    .getByRole('listitem')
    .filter({ hasText: question })
  await expect(mine.getByText('ارسال شد')).toBeAttached({ timeout: 15_000 })

  // ── مدیر ──
  const manager = await signedIn(browser, MANAGER)
  await manager.goto('/messenger')

  const row = manager
    .getByRole('navigation', { name: 'گفت‌وگوها' })
    .getByRole('button')
    .filter({ hasText: question })
  await expect(row, 'گفت‌وگوی واحد با پیامِ ساکن در فهرستِ مدیر نیست.').toBeVisible()
  await expect(row.getByLabel(/پیامِ نخوانده/)).toBeVisible()

  await row.click()
  await expect(manager.getByText(question)).toBeVisible()
  await manager.getByLabel('متنِ پیام').fill(answer)
  await manager.getByRole('button', { name: 'ارسال پیام' }).click()
  await expect(manager.getByText(answer)).toBeVisible()

  // ── ساکن، بی‌بارگذاریِ دوباره ──
  await expect(resident.getByText(answer)).toBeVisible({ timeout: 25_000 })
  await expect(mine.getByText('خوانده شد')).toBeAttached({ timeout: 25_000 })

  await resident.context().close()
  await manager.context().close()
})
