import { expect, test, type Page } from '@playwright/test'

/**
 * بلاگ در مرورگرِ واقعی — از نوشتن تا خواندن (مرحلهٔ ۶ · فاز ۱۱).
 *
 * سوپرادمین در ویرایشگرِ پنل مقاله می‌نویسد و منتشر می‌کند؛ مهمان در صفحه‌ی
 * عمومی آن را می‌خواند، با فهرستِ مطالب جابه‌جا می‌شود و پیوند را کپی می‌کند.
 *
 * ⚠️ مقاله جدول و پیوندِ خیلی بلند دارد: همان دو چیزی که روی گوشی صفحه را
 * افقی سرریز می‌کنند.
 *
 * پیش‌نیاز: `DemoSeeder` و `otp_disabled` (مثلِ بقیه).
 */

const RUN = String(Date.now()).slice(-6)
const ENGINE: Record<string, string> = { chromium: '1', firefox: '2', webkit: '3' }

async function signInAsSuperAdmin(page: Page): Promise<void> {
  await page.goto('/auth')

  const status = await page.evaluate(async () => {
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
      body: JSON.stringify({ phone: '09120000001', password: 'password' }),
    })

    return res.status
  })

  expect(status).toBe(200)
}

test.describe.configure({ mode: 'serial' })

test.describe('بلاگ', () => {
  const title = `راهنمای شارژ ${RUN}`

  test('سوپرادمین در ویرایشگر مقاله می‌نویسد و منتشر می‌کند', async ({ page }, testInfo) => {
    const heading = `${title}-${ENGINE[testInfo.project.name] ?? '9'}`
    await signInAsSuperAdmin(page)

    await page.goto('/system/blog/new')
    await page.getByLabel('عنوان', { exact: true }).fill(heading)
    await page
      .getByLabel('متنِ مقاله')
      .fill(
        [
          '## روش متراژی',
          '',
          'شارژ بر اساسِ متراژِ هر واحد تقسیم می‌شود.',
          '',
          '| واحد | متراژ | سهم |',
          '|---|---|---|',
          '| ۱۰۱ | ۹۰ | ۳۰٪ |',
          '',
          `پیوندِ بلند: https://example.com/${'a'.repeat(160)}`,
          '',
          '## روش نفری',
          '',
          'بر اساسِ تعدادِ ساکنین.',
        ].join('\n'),
      )
    await page.getByLabel('وضعیت').selectOption('published')

    const saved = page.waitForResponse(
      (r) => r.url().includes('/api/v1/system/blog/posts') && r.request().method() === 'POST',
    )
    await page.getByRole('button', { name: 'ذخیره' }).click()
    expect((await saved).status()).toBe(201)

    await expect(page).toHaveURL(/\/system\/blog\/\d+$/)
    await expect(page.getByRole('link', { name: 'پیش‌نمایش' })).toBeVisible()
  })

  test('مهمان می‌خواند، با فهرستِ مطالب جابه‌جا می‌شود و پیوند را کپی می‌کند', async ({
    page,
  }, testInfo) => {
    const heading = `${title}-${ENGINE[testInfo.project.name] ?? '9'}`

    await page.goto('/blog')
    await page.getByRole('link', { name: heading }).click()

    await expect(page.getByRole('heading', { level: 1, name: heading })).toBeVisible()

    // فهرستِ مطالب به عنوانِ واقعی می‌رود
    await page
      .getByRole('navigation', { name: 'فهرستِ مطالب' })
      .getByRole('link', { name: 'روش نفری' })
      .click()
    await expect(page).toHaveURL(/#h-/)
    await expect(page.getByRole('heading', { level: 2, name: 'روش نفری' })).toBeInViewport()

    // مسیرِ راهنما
    const crumbs = page.getByRole('navigation', { name: 'مسیر راهنما' })
    await expect(crumbs.getByRole('link', { name: 'بلاگ' })).toBeVisible()

    // اشتراک: نتیجه‌ی کپی گفته می‌شود (موفق یا ناموفق، هرگز بی‌صدا)
    await page.getByRole('button', { name: /کپیِ پیوند/ }).click()
    await expect(page.getByRole('status').filter({ hasText: /پیوند کپی شد|کپی نشد/ })).toBeVisible()

    // جدول و پیوندِ بلند صفحه را افقی سرریز نمی‌کنند
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )
    expect(overflow, 'صفحه‌ی مقاله افقی سرریز شد').toBeLessThanOrEqual(1)
  })

  test('جست‌وجو پیدا می‌کند و نتیجه ایندکس نمی‌شود', async ({ page }, testInfo) => {
    const heading = `${title}-${ENGINE[testInfo.project.name] ?? '9'}`

    await page.goto('/blog')
    await page.getByRole('searchbox', { name: 'جست‌وجو در بلاگ' }).fill(RUN)
    await page.getByRole('button', { name: 'جست‌وجو' }).click()

    await expect(page.getByRole('link', { name: heading })).toBeVisible()
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', 'noindex,follow')
  })
})
