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

/**
 * غنی‌سازی (مرحلهٔ ۶ · فاز ۱۴): پاسخ، واکنش، ویرایش و حذف — و اینکه همه با
 * همان پرسشِ دوره‌ای به طرفِ مقابل می‌رسند، بی‌بارگذاریِ دوباره.
 */
test('پاسخ، واکنش، ویرایش و حذف به طرفِ مقابل می‌رسند', async ({ browser }, testInfo) => {
  test.setTimeout(120_000)

  const phone = RESIDENTS[testInfo.project.name] ?? '09121111101'
  const tag = `${RUN}-${testInfo.project.name}`
  const first = `کلیدِ انباری کجاست ${tag}`
  const fixed = `کلیدِ انباری کجاست؟ ${tag}`
  const reply = `پیشِ نگهبانی ${tag}`

  const resident = await signedIn(browser, phone)
  await resident.goto('/messenger')
  await resident
    .getByRole('navigation', { name: 'گفت‌وگوها' })
    .getByRole('button', { name: /مدیریتِ ساختمان/ })
    .click()
  await resident.getByLabel('متنِ پیام').fill(first)
  await resident.getByRole('button', { name: 'ارسال پیام' }).click()

  const residentRow = (text: string) =>
    resident.getByRole('list', { name: 'پیام‌ها' }).getByRole('listitem').filter({ hasText: text })
  await expect(residentRow(first).getByText('ارسال شد')).toBeAttached({ timeout: 15_000 })

  // ── مدیر: پاسخ و واکنش از منوی پیام ──
  const manager = await signedIn(browser, MANAGER)
  await manager.goto('/messenger')
  await manager
    .getByRole('navigation', { name: 'گفت‌وگوها' })
    .getByRole('button')
    .filter({ hasText: first })
    .click()

  const managerRow = (text: string) =>
    manager.getByRole('list', { name: 'پیام‌ها' }).getByRole('listitem').filter({ hasText: text })

  await managerRow(first).getByRole('button', { name: 'کارهای پیام' }).click()
  await manager.getByRole('menuitem', { name: 'پاسخ' }).click()
  await manager.getByLabel('متنِ پیام').fill(reply)
  await manager.getByRole('button', { name: 'ارسال پیام' }).click()
  await expect(managerRow(reply)).toBeVisible()

  await managerRow(first).first().getByRole('button', { name: 'کارهای پیام' }).click()
  await manager.getByRole('menuitem', { name: 'واکنش 👍' }).click()

  // ── ساکن: پاسخ با تکه‌متنِ پیامِ خودش، و واکنش روی پیامش ──
  await expect(residentRow(reply)).toBeVisible({ timeout: 25_000 })
  await expect(residentRow(reply).getByRole('button', { name: new RegExp(first) })).toBeVisible()
  await expect(residentRow(first).first().getByRole('button', { name: '👍 1' })).toBeVisible({
    timeout: 25_000,
  })

  // ── ساکن ویرایش می‌کند؛ مدیر متنِ تازه و «ویرایش‌شده» را می‌بیند ──
  await residentRow(first).first().getByRole('button', { name: 'کارهای پیام' }).click()
  await resident.getByRole('menuitem', { name: 'ویرایش' }).click()
  await resident.getByLabel('متنِ پیام').fill(fixed)
  await resident.getByRole('button', { name: 'ذخیره‌ی ویرایش' }).click()
  await expect(managerRow(fixed).getByText('ویرایش‌شده')).toBeVisible({ timeout: 25_000 })

  // ── ساکن حذف می‌کند؛ متن برای مدیر هم می‌رود ──
  await residentRow(fixed).first().getByRole('button', { name: 'کارهای پیام' }).click()
  await resident.getByRole('menuitem', { name: 'حذف' }).click()
  await resident.getByRole('button', { name: 'حذف' }).click()
  await expect(residentRow('این پیام حذف شد').first()).toBeVisible()

  await expect(
    manager.getByRole('list', { name: 'پیام‌ها' }).getByText(fixed, { exact: true }),
  ).toHaveCount(0, { timeout: 25_000 })
  await expect(managerRow('این پیام حذف شد').first()).toBeVisible()
  // 🔴 نقلِ همان پیام در پاسخِ مدیر هم پاک شد — نه فقط خودِ پیام
  await expect(managerRow(reply).getByText('این پیام حذف شد')).toBeVisible({ timeout: 25_000 })

  await resident.context().close()
  await manager.context().close()
})
