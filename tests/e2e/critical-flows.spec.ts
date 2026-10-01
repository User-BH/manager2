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

    /*
     * ⚠️ انتظار برای رندرِ اپ، نه فقط رسیدنِ HTML (R70).
     *
     * بلافاصله پس از `goto` تنها تصویرِ موجود `enamad.png`ِ سرورساید است؛
     * تصویرهای عبوری از Vite را React پس از هیدریشن می‌سازد.
     *
     * 🔴 این را **WebKit لو داد**: کرومیوم به‌قدری سریع بود که تست اتفاقی
     * سبز می‌شد. یعنی ادعا درست بود ولی سنجشش به سرعتِ موتور وابسته —
     * همان تستِ شکننده‌ای که یک روز بی‌دلیل قرمز می‌شود.
     */
    await page.waitForLoadState('networkidle')

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

  test('مسیرِ نامعتبر صفحه‌ی ۴۰۴ را نشان می‌دهد و دکمه‌ی صفحه‌ی اصلی کار می‌کند', async ({
    page,
  }) => {
    await page.goto('/')
    await page.goto('/definitely-not-a-real-route-xyz')
    // ⚠️ پیش از مرحلهٔ ۷ · فاز ۱ اینجا «۴۰۳ — دسترسی غیرمجاز» بود
    await expect(page.getByRole('heading', { name: 'این صفحه پیدا نشد' })).toBeVisible()

    await page.getByRole('button', { name: /صفحه اصلی/ }).click()
    // باید واقعاً به خانه برود (ناوبریِ کاملِ مرورگر، نه فقط تغییرِ آدرس)
    await page.waitForURL((u) => new URL(u).pathname === '/', { timeout: 15_000 })
    await expect(page.locator('h1').first()).toBeVisible()

    // ۴۰۴ بن‌بست است و جایگزین شد: «بازگشت» به صفحه‌ی پیش از آن می‌رود
    await page.goBack()
    await expect(page).not.toHaveURL(/definitely-not-a-real-route-xyz/)
  })

  test('۴۰۴ِ سرورساید فارسی است، نه قالبِ انگلیسیِ لاراول', async ({ page }) => {
    const response = await page.goto('/blog/no-such-post-xyz')

    expect(response?.status()).toBe(404)
    await expect(page.getByRole('heading', { name: 'این صفحه پیدا نشد' })).toBeVisible()
    await expect(page.getByRole('link', { name: 'صفحه‌ی اصلی' })).toBeVisible()
  })
})

/**
 * تم و تاریخچه‌ی مرورگر (مرحلهٔ ۷ · فاز ۱) — فقط در مرورگرِ واقعی سنجیدنی.
 *
 * jsdom نه رنگِ پیش از اولین رنگ‌آمیزی را دارد، نه ناوبریِ سند، نه bfcache.
 */
test.describe('تم و تاریخچه', () => {
  test('سیستم‌عاملِ تیره، تمِ سایت را تیره نمی‌کند', async ({ browser }) => {
    const context = await browser.newContext({ colorScheme: 'dark' })
    const page = await context.newPage()

    await page.goto('/')

    const state = await page.evaluate(() => ({
      dark: document.documentElement.classList.contains('dark'),
      stored: localStorage.getItem('sakena:theme'),
    }))
    // ⚠️ و چیزی هم ذخیره نشده: حدسِ ما «انتخابِ» کاربر نیست
    expect(state).toEqual({ dark: false, stored: null })

    await context.close()
  })

  test('پس از ورود، «بازگشت» به فرمِ ورود نمی‌رسد', async ({ page }) => {
    await page.goto('/')
    await page.goto('/auth')

    await page.locator('input[autocomplete="username"]:visible').fill('09120000000')
    await page.locator('input[autocomplete="current-password"]:visible').fill('password')

    /*
     * پازل با رویدادِ نشانگر حل می‌شود، نه با کشیدنِ ماوسِ Playwright.
     *
     * ⚠️ کشیدن در سه موتور شکننده است (`financial-journey`) و ادعای این
     * آزمون درباره‌ی پازل نیست؛ جای حفره را از خودِ صفحه می‌خوانیم و دسته
     * را همان‌جا رها می‌کنیم — همان رویدادهایی که دستِ کاربر می‌سازد.
     */
    const solved = await page.evaluate(async () => {
      const handle = [
        ...document.querySelectorAll<HTMLElement>('button[aria-label="کشیدن برای حل پازل"]'),
      ].find((b) => b.offsetParent !== null)!
      const track = handle.parentElement!
      const gap = [...track.previousElementSibling!.querySelectorAll<HTMLElement>('*')].find(
        (e) => e.style.left,
      )!
      const x = track.getBoundingClientRect().left + parseFloat(gap.style.left) + 20
      const y = handle.getBoundingClientRect().top + 5
      const init = { bubbles: true, pointerId: 1, pointerType: 'mouse', isPrimary: true }

      handle.dispatchEvent(new PointerEvent('pointerdown', { ...init, clientX: x, clientY: y }))
      window.dispatchEvent(new PointerEvent('pointermove', { ...init, clientX: x, clientY: y }))
      await new Promise((r) => setTimeout(r, 50))
      window.dispatchEvent(new PointerEvent('pointerup', { ...init, clientX: x, clientY: y }))
      await new Promise((r) => setTimeout(r, 200))

      return document.body.innerText.includes('ربات نیستید')
    })
    expect(solved).toBe(true)

    await page.locator('button[type="submit"]:visible').click()
    await page.waitForURL(/\/dashboard/, { timeout: 15_000 })

    // 🔴 پیش از این `assign` بود و «بازگشت» دوباره فرمِ ورود را نشان می‌داد
    await page.goBack()
    await expect(page).toHaveURL((u) => new URL(u).pathname === '/')

    // و کاربرِ واردشده با آدرسِ مستقیم هم فرمِ ورود را نمی‌بیند (سمتِ سرور)
    await page.goto('/auth')
    await expect(page).toHaveURL(/\/dashboard/)
  })
})
