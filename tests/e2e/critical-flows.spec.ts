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

  /*
   * مرحلهٔ ۸ · فاز ۱ · بندِ ۲: تصویرها بی‌اینترنتِ بین‌الملل هم باید بیایند.
   * `selfHostedImages.test.ts` منبع را می‌سنجد؛ این‌جا آنچه مرورگرِ واقعی
   * می‌کشد — شاملِ تصویری که از دیتابیس (بنر، تنظیمات) یا CSS برسد. صفحه تا
   * پایین پیموده می‌شود تا بخش‌ها و تصویرهای تنبل هم بار شوند.
   */
  test('هیچ صفحه‌ی عمومی تصویری از سایتِ دیگر نمی‌کشد', async ({ page, baseURL }) => {
    const origin = new URL(baseURL!).origin
    const foreign: string[] = []
    page.on('request', (request) => {
      if (request.resourceType() === 'image' && !request.url().startsWith('data:')) {
        if (new URL(request.url()).origin !== origin) foreign.push(request.url())
      }
    })

    const article = await page.request
      .get('/api/blog', { headers: { Accept: 'application/json' } })
      .then((r) => r.json() as Promise<{ data: { items: { slug: string }[] } }>)
    const pages = ['/', '/support', '/demo', '/auth', '/blog']
    if (article.data.items[0]) pages.push(`/blog/${encodeURIComponent(article.data.items[0].slug)}`)

    for (const path of pages) {
      await page.goto(path)
      await page.waitForLoadState('networkidle')
      for (let y = 0; y < 12000; y += 700) {
        await page.evaluate((v) => window.scrollTo(0, v), y)
        await page.waitForTimeout(80)
      }
      await page.waitForLoadState('networkidle')
    }

    expect(foreign, foreign.join('\n')).toEqual([])
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
 * فرمِ بازخوردِ صفحه‌ی پشتیبانی (مرحلهٔ ۷ · فاز ۴).
 *
 * ⚠️ CSRF فقط اینجا سنجیدنی است: لاراول در PHPUnit آن را کنار می‌گذارد.
 */
test.describe('فرمِ بازخورد', () => {
  test('بی‌توکنِ CSRF پذیرفته نمی‌شود (۴۱۹)', async ({ request }) => {
    const res = await request.post('/api/v1/feedback', {
      headers: { Accept: 'application/json' },
      data: { category: 'bug', message: 'پیامی بی‌توکن که نباید ثبت شود' },
      failOnStatusCode: false,
    })

    expect(res.status()).toBe(419)
  })

  test('از پیوندِ فوتر تا پیامِ «رسید»', async ({ page }) => {
    await page.goto('/')
    await page.getByRole('link', { name: 'ارسال بازخورد' }).click()
    await page.waitForURL(/\/support#feedback/)

    const form = page.locator('#feedback')
    await expect(form).toBeInViewport({ timeout: 10_000 })

    // مرحلهٔ ۸ · فاز ۵: فهرستِ انتخابیِ سفارشی، با کیبورد — همان راهِ کاربرِ صفحه‌خوان
    const type = form.getByRole('combobox', { name: 'نوعِ پیام' })
    await type.focus()
    await page.keyboard.press('ArrowDown')
    await expect(form.getByRole('listbox')).toBeVisible()
    await page.keyboard.press('Enter')
    await expect(type).toContainText('پیشنهاد')

    await form
      .getByLabel('متنِ پیام')
      .fill(`کاش گزارشِ مالی را هم می‌شد ماهانه چاپ کرد (${test.info().project.name})`)
    // موبایل اجباری است: بی‌آن درخواستی نمی‌رود و فوکوس به خودش می‌رسد
    await form.getByRole('button', { name: 'ارسالِ پیام' }).click()
    await expect(form.getByLabel('شماره موبایل')).toBeFocused()
    await expect(form.getByText('شماره موبایل را وارد کنید.')).toBeVisible()

    await form.getByLabel('شماره موبایل').fill('09121234567')
    await form.getByRole('button', { name: 'ارسالِ پیام' }).click()

    await expect(form.getByRole('status')).toContainText('پیامِ شما رسید')
  })

  /*
   * مرحلهٔ ۸ · فاز ۵ · بندِ ۵: بخشِ بازخورد پس‌زمینه‌ی جدای خودش را تا لبه‌ی صفحه
   * دارد و فهرستِ بازِ «نوعِ پیام» در گوشی بیرون نمی‌زند.
   */
  test('بخشِ بازخورد تمام‌عرض است و فهرستِ نوع در گوشی سرریز نمی‌کند', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 800 })
    await page.goto('/support#feedback')
    const band = page.locator('.feedback-band')
    await expect(band).toBeVisible({ timeout: 10_000 })

    const width = await band.evaluate((el) => Math.round(el.getBoundingClientRect().width))
    // ⚠️ پهنای body، نه clientWidth: ریشه جای نوارِ اسکرول را رزرو می‌کند
    // (`scrollbar-gutter: stable`) و کرومِ دسکتاپ در ‎۳۷۵px آن ‎۱۰px را نشان می‌دهد
    expect(width).toBe(
      await page.evaluate(() => Math.round(document.body.getBoundingClientRect().width)),
    )
    expect(await band.evaluate((el) => getComputedStyle(el).backgroundImage)).toContain('gradient')

    await page.getByRole('combobox', { name: 'نوعِ پیام' }).click()
    await expect(page.getByRole('listbox')).toBeVisible()
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )
    expect(overflow).toBeLessThanOrEqual(1)
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
