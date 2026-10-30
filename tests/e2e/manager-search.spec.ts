import { expect, test, type Browser, type Page } from '@playwright/test'

/**
 * جست‌وجوی مدیر در مرورگرِ واقعی — مرحلهٔ ۱۰ · فاز ۸ (بندهای ۲۰ و ۴۱ ِ فایلِ معماری).
 *
 * دو نمونه‌ی خودِ فایل، با ارقامِ فارسی که کاربر واقعاً می‌نویسد: «مستأجر واحد ۴» در جست‌وجوی کلی همان یک
 * مستأجرِ فعلی را می‌آورد، و در پیام‌رسان همان رشته‌ی واحد را. مرزِ مجتمع و صفحه‌بندی در PHPUnit سنجیده
 * می‌شوند (`ManagerSearchTest`، `ConversationSearchTest`)؛ این‌جا فقط اینکه رابط همان را به سرور می‌برد و
 * نشان می‌دهد.
 *
 * پیش‌نیاز: `DemoSeeder` (واحدِ ۴ اجاره‌ای است: مستأجرِ 09122222204؛ واحدِ ۱ مستأجر ندارد).
 */

const MANAGER = '09120000000'

async function signedIn(browser: Browser, phone: string): Promise<Page> {
  const page = await (await browser.newContext()).newPage()
  await page.goto('/auth')

  const status = await page.evaluate(
    async (creds) => {
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
        creds.phone === '09120000001'
          ? await send('/api/admin/login', { username: 'sakena.admin', password: 'password' })
          : await send('/api/login', { phone: creds.phone }).then(async (r) =>
              r.ok ? send('/api/login/verify', { code: (await r.json()).dev_code }) : r,
            )
      return res.status
    },
    { phone },
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

  // ── پیام‌رسان: گفت‌وگوی مدیر با مستأجرِ واحدِ ۴ (۱۰/۹: با هر عضو، نه با واحد؛ یافتن یا ساختن) ──
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
    const member = (list.members as { id: number; label: string }[]).find((m) =>
      m.label.endsWith('مستاجر واحد 4'),
    )
    if (!member) return 0
    const res = await fetch('/api/v1/messenger/conversations', {
      method: 'POST',
      credentials: 'include',
      headers,
      body: JSON.stringify({ type: 'user', user_id: member.id }),
    })
    return res.status
  })
  expect(created).toBe(201)

  await manager.goto('/messenger')
  const list = manager.getByRole('navigation', { name: 'گفت‌وگوها' })
  const box = manager.getByRole('searchbox', { name: 'جست‌وجوی گفت‌وگوها' })

  await box.fill('مستأجر واحد ۴')
  await expect(list.getByRole('button')).toHaveCount(1)
  await expect(list.getByRole('button', { name: /مستاجر واحد 4/ })).toBeVisible()

  // واحدِ ۱ مستأجر ندارد: هیچ، نه گفت‌وگوی مالکش
  await box.fill('مستأجر واحد ۱')
  await expect(list.getByText('گفت‌وگویی با «مستأجر واحد ۱» پیدا نشد.')).toBeVisible()

  await manager.context().close()
})
