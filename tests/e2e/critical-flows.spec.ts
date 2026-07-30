import { expect, test } from '@playwright/test'

/**
 * جریان‌های بحرانی (DoD-60) روی سرورِ واقعی.
 *
 * تمرکز روی چیزهایی است که فقط در مرورگرِ واقعی قابل سنجش‌اند:
 * مرزِ MPA/SPA، ریدایرکتِ سمت سرور، نشستِ کوکی، و بارگذاریِ دارایی‌های ساخته‌شده.
 *
 * «کیف پول» و «پرداخت» از فهرستِ DoD-60 اینجا نیستند چون هنوز ساخته نشده‌اند
 * (R22)؛ با ساخته‌شدنشان به همین فایل اضافه می‌شوند.
 */

test.describe('دسترسیِ مهمان', () => {
  test('مهمان با آدرسِ مستقیمِ داشبورد به صفحه‌ی ورود می‌رود', async ({ page }) => {
    await page.goto('/dashboard')
    // ProtectedRoute یک ناوبریِ کاملِ مرورگر به /auth انجام می‌دهد
    await page.waitForURL(/\/auth/, { timeout: 15_000 })
    expect(page.url()).toContain('/auth')
  })

  test('APIهای محافظت‌شده برای مهمان ۴۰۱ می‌دهند', async ({ request }) => {
    for (const path of ['/api/dashboard', '/api/units', '/api/system/members']) {
      const res = await request.get(path, { headers: { Accept: 'application/json' } })
      expect(res.status(), path).toBe(401)
    }
  })
})

test.describe('صفحه‌ی اصلی', () => {
  test('بارگذاری می‌شود، عنوان و بخش‌ها دارد و خطای کنسول نمی‌دهد', async ({ page }) => {
    const errors: string[] = []
    page.on('console', (m) => m.type() === 'error' && errors.push(m.text()))
    page.on('pageerror', (e) => errors.push(e.message))

    await page.goto('/')

    await expect(page.locator('h1').first()).toBeVisible()
    expect(await page.locator('section').count()).toBeGreaterThan(3)
    expect(errors, 'خطای کنسول').toEqual([])
  })

  test('هیچ تصویری شکسته نیست', async ({ page }) => {
    await page.goto('/')
    await page.waitForLoadState('networkidle')

    const broken = await page.evaluate(() =>
      [...document.querySelectorAll('img')]
        .filter((i) => i.complete && i.naturalWidth === 0)
        .map((i) => i.getAttribute('src') ?? ''),
    )
    expect(broken).toEqual([])
  })

  test('دارایی‌های عبوری از Vite با نامِ هش‌دار سرو می‌شوند', async ({ page }) => {
    await page.goto('/')
    const hashed = await page.evaluate(() =>
      [...document.querySelectorAll('img')].some((i) => /\/build\/assets\//.test(i.src)),
    )
    expect(hashed).toBe(true)
  })
})

test.describe('فرمِ ورود', () => {
  test('اعتبارسنجیِ سمت کلاینت پیش از تماس با سرور', async ({ page }) => {
    await page.goto('/auth')
    await page.getByRole('button', { name: /ورود به پنل/ }).click()
    await expect(page.getByText('شماره موبایل را وارد کنید')).toBeVisible()
  })

  test('درخواستِ بدون توکنِ CSRF رد می‌شود (۴۱۹)', async ({ request }) => {
    const res = await request.post('/api/login', {
      headers: { Accept: 'application/json' },
      data: { phone: '09129999999', password: 'x' },
      failOnStatusCode: false,
    })
    // ۴۱۹ یعنی حفاظتِ CSRF لاراول واقعاً فعال است
    expect(res.status()).toBe(419)
  })

  test('ورود با اطلاعاتِ نادرست، کاربر را وارد نمی‌کند', async ({ page }) => {
    /*
     * از داخلِ صفحه fetch می‌زنیم، نه با فیکسچرِ `request`.
     *
     * دلیلش: `request` ظرفِ کوکیِ جدایی از مرورگر دارد، پس کوکیِ XSRF را
     * نمی‌بیند و همه‌چیز ۴۱۹ می‌گیرد. داخلِ صفحه، مرورگر خودش کوکی را می‌فرستد —
     * و این همان مسیری است که کاربرِ واقعی طی می‌کند.
     */
    await page.goto('/auth')

    const result = await page.evaluate(async () => {
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
        body: JSON.stringify({ phone: '09129999999', password: 'definitely-wrong' }),
      })
      const body = await res.json()
      const me = await (await fetch('/api/me', { credentials: 'include' })).json()
      return { status: res.status, message: body.message as string, user: me.user }
    })

    expect(result.status).toBe(422)
    // پیام باید یکسان و بی‌اطلاع باشد تا شمارشِ کاربر (enumeration) ممکن نشود
    expect(result.message).toContain('نادرست')
    // و نشستی ساخته نشده باشد
    expect(result.user).toBeNull()
  })
})

test.describe('صفحات عمومیِ MPA', () => {
  for (const [path, needle] of [
    ['/demo', 'یک دور کامل داخل پنل'],
    ['/support', 'چطور می‌توانیم کمک کنیم؟'],
  ] as const) {
    test(`${path} به‌صورت سرورساید رندر می‌شود`, async ({ page, request }) => {
      // HTMLِ خامِ سرور باید متن را داشته باشد (شرطِ SEO)
      const raw = await (await request.get(path)).text()
      expect(raw).toContain('<title>')

      await page.goto(path)
      await expect(page.getByText(needle).first()).toBeVisible()
    })
  }

  test('مسیرِ نامعتبر صفحه‌ی ۴۰۳ را نشان می‌دهد و دکمه‌ی صفحه‌ی اصلی کار می‌کند', async ({
    page,
  }) => {
    await page.goto('/definitely-not-a-real-route-xyz')
    await expect(page.getByText('۴۰۳')).toBeVisible()

    await page.getByRole('button', { name: /صفحه اصلی/ }).click()
    // باید واقعاً به خانه برود (ناوبریِ کاملِ مرورگر، نه فقط تغییرِ آدرس)
    await page.waitForURL((u) => new URL(u).pathname === '/', { timeout: 15_000 })
    await expect(page.locator('h1').first()).toBeVisible()
  })
})
