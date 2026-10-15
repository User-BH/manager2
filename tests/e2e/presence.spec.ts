import { expect, test, type Browser, type Page } from '@playwright/test'

/**
 * حضورِ آنلاین در مرورگرِ واقعی — «چند نکته»، بندِ ۳.
 *
 * «دیده‌شونده» فقط داشبورد را باز می‌کند (ضربان همان pollingهای موجود است، نه درخواستِ تازه)؛ «بیننده» در گروهِ
 * مجتمع شمارِ آنلاین و نقطه‌ی سبزِ او را می‌بیند. دیده‌شونده صفحه را ترک می‌کند (`pagehide` ← fetch ِ keepalive)
 * و نقطه با تازه‌شدنِ ۳۰ ثانیه‌ایِ پنلِ اعضا خاموش می‌شود — **پیش از** پنجره‌ی ۹۰ ثانیه، پس کارِ همان «آفلاین شدم»
 * است، نه گذشتِ زمان. قاعده‌ها و مرزِ دیدن در `PresenceTest`.
 *
 * پیش‌نیاز: `DemoSeeder`، `E2eSeeder` (حساب‌های حضورِ هر موتور) و `otp_disabled`.
 */

const SUBJECTS: Record<string, string> = {
  chromium: '09120000091',
  firefox: '09120000092',
  webkit: '09120000093',
}
const VIEWERS: Record<string, string> = {
  chromium: '09120000094',
  firefox: '09120000095',
  webkit: '09120000096',
}

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

test('آنلاین در گروه دیده می‌شود و ترکِ صفحه پیش از پایانِ پنجره خاموشش می‌کند', async ({
  browser,
}, testInfo) => {
  test.setTimeout(120_000)
  const project = testInfo.project.name
  const n = Object.keys(SUBJECTS).indexOf(project) + 1
  test.skip(n === 0, 'حساب‌های حضور فقط برای سه موتورِ دسکتاپ ساخته شده‌اند.')

  // ── دیده‌شونده: فقط داشبورد؛ اولین پرسشِ اعلان‌ها ضربان است ──
  const subject = await signedIn(browser, SUBJECTS[project])
  const heartbeat = subject.waitForResponse(
    (r) => r.url().includes('/api/v1/notifications') && r.ok(),
  )
  await subject.goto('/dashboard')
  await heartbeat
  const seenAt = Date.now()

  // ── بیننده: گروهِ مجتمع، شمارِ آنلاین و نقطه‌ی کنارِ دیده‌شونده ──
  const viewer = await signedIn(browser, VIEWERS[project])
  await viewer.goto('/messenger')
  await viewer
    .getByRole('navigation', { name: 'گفت‌وگوها' })
    .getByRole('button', { name: /^گروهِ / })
    .click()
  await expect(viewer.locator('[data-presence="count"]').first()).toContainText('آنلاین')

  await viewer.getByRole('button', { name: /^اطلاعاتِ گروهِ / }).click()
  const row = viewer
    .getByRole('list', { name: 'اعضای گروه' })
    .getByRole('listitem')
    .filter({ hasText: `حاضر سرتاسری ${n}` })
  await expect(row).toBeVisible()
  await expect(row.locator('[data-presence="online"]')).toBeVisible()

  // ── دیده‌شونده صفحه را ترک می‌کند ──
  await subject.goto('about:blank')

  // پنلِ باز هر ۳۰ ثانیه تازه می‌شود؛ بی «آفلاین شدم» نقطه تا ۹۰ ثانیه پس از آخرین ضربان می‌ماند
  await expect(row.locator('[data-presence="online"]')).toHaveCount(0, { timeout: 45_000 })
  expect(Date.now() - seenAt, 'خاموش شدن کارِ پنجره‌ی ۹۰ ثانیه بود، نه «آفلاین شدم».').toBeLessThan(
    85_000,
  )

  await subject.context().close()
  await viewer.context().close()
})
