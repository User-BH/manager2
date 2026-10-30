import { expect, test, type Browser, type Page } from '@playwright/test'

/**
 * امنیتِ نشست در مرورگرِ واقعی (مرحلهٔ ۵ · فاز ۱۰).
 *
 * سه چیز که فقط با دو مرورگر یا دو تبِ واقعی دیده می‌شود:
 *
 * ۱. «خروج از همه‌ی دستگاه‌های دیگر» پنجره‌ی رمز را باز می‌کند، و پس از
 *    تأیید، مرورگرِ دیگر واقعاً بیرون است.
 * ۲. پنجره‌ی رمز خودکار است: همان درخواستی که سرور رد کرده بود پس از تأیید
 *    دوباره می‌رود و کاربر کارِ دیگری نمی‌کند.
 * ۳. خروج در یک تب، تبِ دیگرِ همان مرورگر را هم به صفحه‌ی ورود می‌برد.
 *
 * ─── پیش‌نیازِ محیط ────────────────────────────────────────────────────────
 * `DemoSeeder` (مثلِ بقیه)، و `AUTH_PASSWORD_TIMEOUT=5`:
 * ورود خودش رمز را تأیید می‌کند، پس بدونِ پنجره‌ی کوتاه، پنجره‌ی رمز هرگز
 * دیده نمی‌شد و بخشِ دومِ آزمون چیزی نمی‌سنجید.
 */

// مستأجرهای نمایشی؛ سفرِ مالی مالک‌ها را به کار می‌برد
const TENANTS: Record<string, string> = {
  chromium: '09122222202',
  firefox: '09122222204',
  webkit: '09122222206',
}

async function signIn(page: Page, phone: string): Promise<void> {
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
}

async function status(page: Page, path: string): Promise<number> {
  return page.evaluate(
    async (url) =>
      (await fetch(url, { credentials: 'include', headers: { Accept: 'application/json' } }))
        .status,
    path,
  )
}

async function freshBrowser(browser: Browser, phone: string): Promise<Page> {
  const context = await browser.newContext()
  const page = await context.newPage()
  await signIn(page, phone)
  return page
}

test.describe.configure({ mode: 'serial' })

test.describe('امنیتِ نشست', () => {
  test('خروج از همه‌ی دستگاه‌های دیگر، با تأییدِ کدِ پیامکی', async ({ browser }, testInfo) => {
    const phone = TENANTS[testInfo.project.name] ?? '09122222202'

    const laptop = await freshBrowser(browser, phone)
    const stolen = await freshBrowser(browser, phone)

    expect(await status(stolen, '/api/v1/profile')).toBe(200)

    // پنجره‌ی تأییدِ ورود بسته شود
    await laptop.waitForTimeout(6_000)

    await laptop.goto('/profile')
    await laptop.getByRole('button', { name: 'خروج از همه‌ی دستگاه‌های دیگر' }).click()
    await laptop.getByRole('button', { name: 'خارج کن' }).click()

    const dialog = laptop.getByRole('dialog', { name: 'تأییدِ دوباره' })
    await expect(
      dialog,
      'پنجره‌ی تأیید باز نشد؛ AUTH_PASSWORD_TIMEOUT=5 برای این آزمون لازم است.',
    ).toBeVisible()

    // کاربر رمز ندارد (۲۰۲۶-۱۰-۳۰): کدی به شماره‌ی خودش؛ با درایورِ log کد در همان پنجره است
    await dialog.getByRole('button', { name: 'ارسال کد' }).click()
    const hint = dialog.getByText(/کدِ محیطِ آزمایشی:/)
    const code = ((await hint.innerText()).match(/\d{6}/) ?? [''])[0]
    await dialog.getByLabel('کدِ پیامک‌شده').fill(code)

    const ended = laptop.waitForResponse(
      (r) => r.url().includes('/profile/sessions/end-others') && r.status() === 200,
    )
    await dialog.getByRole('button', { name: 'تأیید' }).click()

    // 🔴 همان درخواستی که سرور رد کرده بود، خودکار دوباره رفت
    await ended
    await expect(dialog).toBeHidden()

    expect(await status(stolen, '/api/v1/profile'), '🔴 مرورگرِ دیگر هنوز وارد است.').toBe(401)
    expect(await status(laptop, '/api/v1/profile'), 'خودِ این دستگاه نباید بیرون بیفتد.').toBe(200)

    await stolen.context().close()
    await laptop.context().close()
  })

  test('خروج در یک تب، تبِ دیگر را هم بیرون می‌برد', async ({ browser }, testInfo) => {
    const phone = TENANTS[testInfo.project.name] ?? '09122222202'

    const first = await freshBrowser(browser, phone)
    const second = await first.context().newPage()

    await second.goto('/dashboard')
    await first.goto('/dashboard')

    await first.getByRole('button', { name: /مستاجر واحد/ }).click()
    await first.getByText('خروج از حساب').click()

    await first.waitForURL(/\/auth/, { timeout: 15_000 })
    await second.waitForURL(/\/auth/, { timeout: 15_000 })

    await first.context().close()
  })
})
