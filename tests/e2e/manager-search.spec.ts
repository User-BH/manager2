import { expect, test, type Browser, type Page } from '@playwright/test'

/**
 * جست‌وجوی مدیر در مرورگرِ واقعی — مرحلهٔ ۱۰ · فاز ۸ (بندهای ۲۰ و ۴۱ ِ فایلِ معماری).
 *
 * دو نمونه‌ی خودِ فایل، با ارقامِ فارسی که کاربر واقعاً می‌نویسد: «مستأجر واحد ۴» در جست‌وجوی کلی همان یک
 * مستأجرِ فعلی را می‌آورد، و در پیام‌رسان همان رشته‌ی واحد را. مرزِ مجتمع و صفحه‌بندی در PHPUnit سنجیده
 * می‌شوند (`ManagerSearchTest`، `ConversationSearchTest`)؛ این‌جا فقط اینکه رابط همان را به سرور می‌برد و
 * نشان می‌دهد.
 *
 * پیش‌نیاز: `DemoSeeder` (واحدِ ۴ اجاره‌ای است: مستأجرِ 09122222204؛ واحدِ ۱ مستأجر ندارد) و `otp_disabled`.
 */

const MANAGER = '09120000000'

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

test('«مستأجر واحد ۴» در جست‌وجوی کلی و در پیام‌رسانِ مدیر', async ({ browser }) => {
  test.setTimeout(90_000)
  const manager = await signedIn(browser, MANAGER)

  // ── جست‌وجوی کلی ──
  await manager.goto(`/search?q=${encodeURIComponent('مستأجر واحد ۴')}`)
  const residents = manager
    .getByRole('heading', { name: /ساکنین/ })
    .locator('xpath=ancestor::*[.//ul][1]')
  const rows = residents.getByRole('listitem')
  await expect(rows).toHaveCount(1)
  await expect(rows.first()).toContainText(/۰۹۱۲۲۲۲۲۲۰۴|09122222204/)

  // ── پیام‌رسان: رشته‌ی واحدِ ۴ (یافتن یا ساختن — تکرارش چیزی نمی‌سازد) ──
  const created = await manager.evaluate(async () => {
    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '')
    const headers = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-XSRF-TOKEN': xsrf,
    }
    const list = await (
      await fetch('/api/v1/messenger/conversations', { credentials: 'include', headers })
    ).json()
    const unit = (list.units as { id: number; label: string }[]).find((u) => u.label === 'واحد 4')
    if (!unit) return 0
    const res = await fetch('/api/v1/messenger/conversations', {
      method: 'POST',
      credentials: 'include',
      headers,
      body: JSON.stringify({ type: 'unit', unit_id: unit.id }),
    })
    return res.status
  })
  expect(created).toBe(201)

  await manager.goto('/messenger')
  const list = manager.getByRole('navigation', { name: 'گفت‌وگوها' })
  const box = manager.getByRole('searchbox', { name: 'جست‌وجوی گفت‌وگوها' })

  await box.fill('مستأجر واحد ۴')
  await expect(list.getByRole('button')).toHaveCount(1)
  await expect(list.getByRole('button', { name: /واحد 4/ })).toBeVisible()

  // واحدِ ۱ مستأجر ندارد: هیچ، نه رشته‌ی واحدِ ۱
  await box.fill('مستأجر واحد ۱')
  await expect(list.getByText('گفت‌وگویی با «مستأجر واحد ۱» پیدا نشد.')).toBeVisible()

  await manager.context().close()
})
