import { expect, test, type Page, type Response } from '@playwright/test'

/**
 * داشبوردِ ثبت‌نام‌کننده‌ی اولیه با **همان credential ِ تحویلی** — مرحلهٔ ۱۲ · فاز ۵ (بندهای ۲۸، ۳۱ و ۳۴ ِ سندِ هسته).
 *
 * بندِ ۲۸ می‌خواهد وجودِ داشبورد با ورودِ واقعی سنجیده شود، نه فرض. `09120000002` / `password` همان حسابی است که
 * `DemoSeeder::FRESH_SIGNUP_PHONE` می‌سازد و به کارفرما داده شده؛ ورودش تا پیش از این فقط در سطحِ HTTP سنجیده می‌شد
 * (`DemoSeederTest`). این‌جا در مرورگر: حالت را سرور می‌گوید (`initial`)، پوسته‌ی جدا بالا می‌آید، هر آیتمِ منو به
 * صفحه‌ای واقعی با پاسخِ واقعیِ سرور می‌رسد (هیچ درخواستِ API ِ ناموفق)، و مسیر و APIِ مدیر بسته است.
 *
 * ⚠️ این آزمون چیزی نمی‌نویسد: همان حساب در سه موتور و برای کارفرما دست‌نخورده می‌ماند.
 * ورود با `fetch` است، همان دلیلِ سفرهای دیگر (پازلِ کشویی ِ فرمِ ورود)؛ خودِ مسیرِ ورود همان `POST /api/login` است.
 */

const PHONE = '09120000002'

async function signIn(page: Page) {
  await page.context().clearCookies()
  await Promise.all([
    page.waitForResponse((r) => /\/me$/.test(new URL(r.url()).pathname)),
    page.goto('/auth'),
  ])
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
  }, PHONE)
  expect(status, `ورودِ ${PHONE}`).toBe(200)
}

async function api(page: Page, path: string) {
  return page.evaluate(async (path) => {
    const res = await fetch(path, {
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })
    return {
      status: res.status,
      json: (await res.json().catch(() => null)) as Record<string, unknown> | null,
    }
  }, path)
}

const NAV = [
  { label: 'داشبورد', path: '/dashboard' },
  { label: 'اعلان‌ها', path: '/notifications' },
  { label: 'پروفایل', path: '/profile' },
  { label: 'حساب و اشتراک', path: '/account' },
  { label: 'کیف پول', path: '/wallet' },
]

test('credential ِ ثبت‌نام‌کننده‌ی اولیه: ورودِ واقعی، حالتِ سرور، منوی واقعی و درهای بسته', async ({
  page,
}) => {
  await signIn(page)

  // ── بندِ ۳۴: حالت را سرور می‌گوید، نه پرچمِ رابط ──
  const me = await api(page, '/api/v1/me')
  expect(me.status).toBe(200)
  expect(me.json?.user).toMatchObject({
    phone: PHONE,
    accountState: 'initial',
    dashboard: 'initial',
    complex: null,
  })

  // ── هر درخواستِ API ِ ناموفق در گشتِ منو ثبت می‌شود: منوی جعلی یا صفحه‌ی بی‌پشتوانه همین‌جا پیدا می‌شود ──
  const failed: string[] = []
  const watch = (r: Response) => {
    const url = new URL(r.url())
    if (url.pathname.startsWith('/api/') && r.status() >= 400)
      failed.push(`${r.status()} ${url.pathname}`)
  }
  page.on('response', watch)

  await page.goto('/dashboard')
  await expect(page.locator('[data-experience="registrant"]')).toBeAttached({ timeout: 15_000 })
  const menu = page.getByRole('navigation', { name: 'منوی حساب' })
  await expect(menu.getByRole('link')).toHaveCount(NAV.length)

  for (const item of NAV) {
    await menu.getByRole('link', { name: item.label }).click()
    await expect(page).toHaveURL(new RegExp(`${item.path}$`))
    await expect(menu.getByRole('link', { name: item.label })).toHaveAttribute(
      'aria-current',
      'page',
    )
    await expect(page.locator('#main-content')).not.toBeEmpty()
    await page.waitForLoadState('networkidle')
  }

  page.off('response', watch)
  expect(failed, 'درخواستِ API ِ ناموفق در منوی داشبوردِ اولیه').toEqual([])

  // ── راهنما و پشتیبانی و دمو صفحه‌های واقعی‌اند ──
  for (const path of ['/support', '/demo']) {
    expect((await page.request.get(path)).status(), path).toBe(200)
  }

  // ── درِ مدیر و ساکن: مسیرِ رابط به داشبوردِ خودش برمی‌گردد و API رد می‌کند (سمتِ سرور) ──
  await page.goto('/units')
  await expect(page).toHaveURL(/\/dashboard$/)
  // مسیرهای مدیر بسته‌اند؛ خواندنِ ساکن برای حسابِ اولیه باز است ولی دامنه‌اش «هیچ» است (`InitialAccountTest`)
  for (const path of ['/api/v1/units', '/api/v1/residents']) {
    expect([401, 403, 404], path).toContain((await api(page, path)).status)
  }
  const bills = await api(page, '/api/v1/my-bills')
  expect(bills.status).toBe(200)
  expect(bills.json?.data, 'حسابِ اولیه قبضی دید').toEqual([])
})
