import { expect, test, type Browser, type Page } from '@playwright/test'

/**
 * ساختارِ پیام‌رسان در مرورگرِ واقعی — مرحلهٔ ۱۰ · فاز ۹ (بندهای ۳۵، ۳۷، ۳۸، ۳۹ و ۴۰ ِ فایلِ معماری).
 *
 * مستأجرِ واحدِ ۴ از همان بارِ اول گروهِ مجتمع، مدیریت، «ذخیره‌شده» و مالکش را دارد؛ در «مالک ↔ مستأجر» می‌نویسد
 * و مالک همان را می‌بیند؛ مدیر آن گفت‌وگو را نه در فهرست می‌بیند نه در جست‌وجو؛ کلیک روی نامِ گروه اطلاعاتش را
 * باز می‌کند. قاعده‌های دسترسی در `MessengerStructureTest` سنجیده می‌شوند.
 *
 * پیش‌نیاز: `DemoSeeder` (واحدِ ۴ اجاره‌ای است: مالکِ 09121111104، مستأجرِ 09122222204) و `otp_disabled`.
 */

const OWNER = '09121111104'
const TENANT = '09122222204'
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

test('مالک ↔ مستأجر، ذخیره‌شده و اطلاعاتِ گروه — و مدیر گفت‌وگوی خصوصیِ آن دو را نمی‌بیند', async ({
  browser,
}, testInfo) => {
  test.setTimeout(90_000)
  const text = `کلیدِ انباری کجاست ${RUN}-${testInfo.project.name}`

  // ── مستأجر: چهار گفت‌وگو از همان بارِ اول ──
  const tenant = await signedIn(browser, TENANT)
  await tenant.goto('/messenger')
  const tenantList = tenant.getByRole('navigation', { name: 'گفت‌وگوها' })
  await expect(tenantList.getByRole('button', { name: /^گروهِ / })).toBeVisible()
  await expect(tenantList.getByRole('button', { name: /مدیریتِ ساختمان/ })).toBeVisible()
  await expect(tenantList.getByRole('button', { name: /پیام‌های ذخیره‌شده/ })).toBeVisible()

  await tenantList.getByRole('button', { name: /· مالک/ }).click()
  await tenant.getByLabel('متنِ پیام').fill(text)
  await tenant.getByRole('button', { name: 'ارسال پیام' }).click()
  await expect(
    tenant
      .getByRole('list', { name: 'پیام‌ها' })
      .getByRole('listitem')
      .filter({ hasText: text })
      .getByText('ارسال شد'),
  ).toBeAttached({ timeout: 15_000 })

  // ── مالک: همان گفت‌وگو؛ و اطلاعاتِ گروه با کلیک روی نامش ──
  const owner = await signedIn(browser, OWNER)
  await owner.goto('/messenger')
  const ownerList = owner.getByRole('navigation', { name: 'گفت‌وگوها' })
  await ownerList.getByRole('button', { name: /· مستأجر/ }).click()
  await expect(owner.getByRole('list', { name: 'پیام‌ها' }).getByText(text)).toBeVisible()

  await ownerList.getByRole('button', { name: /^گروهِ / }).click()
  await owner.getByRole('button', { name: /^اطلاعاتِ گروهِ / }).click()
  const members = owner.getByRole('list', { name: 'اعضای گروه' })
  await expect(members.getByRole('listitem').first()).toBeVisible()
  await expect(owner.getByRole('dialog').getByText('مستأجران')).toBeVisible()

  // ── مدیر: نه در فهرست، نه در جست‌وجو ──
  const manager = await signedIn(browser, MANAGER)
  await manager.goto('/messenger')
  const managerList = manager.getByRole('navigation', { name: 'گفت‌وگوها' })
  await expect(managerList.getByRole('button', { name: /^گروهِ / })).toBeVisible()
  await expect(managerList.getByRole('button', { name: /· مالک|· مستأجر/ })).toHaveCount(0)
  await expect(managerList.getByText(text)).toHaveCount(0)

  const total = await manager.evaluate(async (q) => {
    const res = await fetch(`/api/v1/search?q=${encodeURIComponent(q)}`, {
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })
    return ((await res.json()) as { total: number }).total
  }, text)
  expect(total, 'جست‌وجوی مدیر پیامِ گفت‌وگوی مالک و مستأجر را یافت.').toBe(0)

  await Promise.all([tenant, owner, manager].map((page) => page.context().close()))
})
