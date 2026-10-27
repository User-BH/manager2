import { expect, test, type Page } from '@playwright/test'

/**
 * خریدِ آنلاینِ اشتراک و ساختِ مجتمع توسطِ حسابِ اولیه (مرحلهٔ ۹ · فاز ۴).
 *
 * مسیرِ سرور در مرورگرِ واقعی: فرمِ خرید ← درگاهِ sandbox ← بازگشت ← تأییدِ سرور
 * ← در یک تراکنش مجتمع + اشتراک + مدیر ← داشبوردِ مدیر. رابطِ انتخابِ پلن برای
 * حسابِ اولیه از ۹/۵ صفحه‌ی خودش را دارد و همین تست از همان رابط می‌خرد.
 *
 * پیش‌نیاز: `SUBSCRIPTION_GATEWAY=sandbox` و `PAYMENT_SANDBOX_ENABLED=true` (مثلِ CI)
 * و `E2eSeeder::BUYERS`. هر موتور خریدارِ خودش را دارد.
 */

/** همان `E2eSeeder::BUYERS` */
/** پسوندِ فارسیِ هر موتور — فیلدِ نامِ مجتمع حرفِ لاتین را نمی‌پذیرد */
const SUFFIX: Record<string, string> = { chromium: 'یک', firefox: 'دو', webkit: 'سه' }

const BUYERS: Record<string, string> = {
  chromium: '09120000041',
  firefox: '09120000042',
  webkit: '09120000043',
}

async function signIn(page: Page, phone: string) {
  await page.context().clearCookies()
  await page.goto('/auth')
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
  }, phone)
  expect(status, `ورودِ ${phone}`).toBe(200)
}

async function me(page: Page) {
  return page.evaluate(async () => {
    const res = await fetch('/api/me', {
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })
    return (
      (await res.json()) as {
        user: {
          id: number
          name: string
          firstName: string | null
          lastName: string | null
          phone: string
          accountState: string
          role: string
          complex: { name: string } | null
        }
      }
    ).user
  })
}

/**
 * از رابطِ واقعی (مرحلهٔ ۹ · فاز ۵؛ جادوگرِ ۱۳/۶): داشبوردِ اولیه ← «مشاهده‌ی پکیج‌ها» ← نوعِ مجتمع ← پلن ← مدت ←
 * تأیید (نام) ← «تأیید و پرداخت». مبلغ را رابط نمی‌فرستد؛ سرور از جدولِ قیمت می‌خواند.
 */
async function submitCheckout(page: Page, complexName: string) {
  await page.goto('/dashboard')
  await page.getByRole('link', { name: 'مشاهده‌ی پکیج‌ها' }).click()
  await expect(
    page.getByRole('heading', { level: 1, name: 'ساختِ مجتمع و خریدِ اشتراک' }),
  ).toBeVisible()
  await page.getByRole('button', { name: /^مسکونی/ }).click()
  await page.getByRole('button', { name: /^پایه/ }).first().click()
  await page.getByRole('button', { name: /^۱ ماهه/ }).click()
  await expect(page.getByRole('heading', { name: 'تأییدِ نهایی' })).toBeVisible()

  /*
   * ایرادات ۱۰، بندِ ۱۹ و DoD ِ بندِ ۱۱۰ («Navigation و Breadcrumb»؛ آشتیِ ماتریس در ۱۳/۹): نوارِ راهنمای مراحلِ خرید
   * در عرضِ گوشی هم کامل دیده می‌شود، مرحله‌ی جاری مشخص است و صفحه افقی سرریز نمی‌کند.
   */
  const viewport = page.viewportSize()
  await page.setViewportSize({ width: 375, height: 812 })
  const steps = page.getByRole('navigation', { name: 'مراحلِ خرید' })
  await expect(steps.locator('[aria-current="step"]')).toHaveText(/تأیید/)
  for (const step of ['نوع مجتمع', 'پلن', 'مدت اشتراک', 'تأیید', 'پرداخت']) {
    await expect(steps.getByText(step, { exact: true })).toBeVisible()
  }
  const box = (await steps.boundingBox())!
  expect(box.x).toBeGreaterThanOrEqual(0)
  expect(box.x + box.width, 'نوارِ راهنما از عرضِ گوشی بیرون زد').toBeLessThanOrEqual(375)
  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  )
  expect(overflow, 'صفحه‌ی خرید در عرضِ گوشی افقی سرریز کرد').toBeLessThanOrEqual(0)
  if (viewport) await page.setViewportSize(viewport)

  await page.getByLabel(/نامِ مجتمع/).fill(complexName)

  /*
   * رسید هیچ نقشی در مدیر شدن ندارد (F1، F2): نه فایلی، نه دکمه‌ی رسیدی.
   * ⚠️ متن نه: «پرداخت با آپلود رسید» در امکاناتِ پلنِ پایه است و درباره‌ی
   * پرداختِ قبضِ ساکنان — محتوای درست. نسخه‌ی اولِ این بررسی روی متن بود و فقط
   * چون پیش از نمایشِ پلن‌ها اجرا می‌شد سبز می‌ماند.
   */
  await expect(page.locator('input[type="file"]')).toHaveCount(0)
  await expect(page.getByRole('button', { name: /رسید/ })).toHaveCount(0)
  await page.getByRole('button', { name: 'تأیید و پرداخت' }).click()
}

test.describe.configure({ mode: 'serial' })

test.describe('خریدِ آنلاینِ اشتراک', () => {
  test('بازگشتِ دست‌کاری‌شده هیچ مجتمع یا مدیری نمی‌سازد', async ({ page }, testInfo) => {
    const phone = BUYERS[testInfo.project.name] ?? BUYERS.chromium
    await signIn(page, phone)

    /*
     * مرجعِ sandbox در نشانیِ بازگشت عوض می‌شود — مثلِ کسی که بازگشتِ دیگری را جعل کند.
     *
     * ⚠️ نه با `route` روی خودِ بازگشت: Playwright درخواستی را که از هدایتِ سرور
     * (۳۰۲) می‌آید به `route` نمی‌دهد، و نسخه‌ی اولِ این تست بی‌صدا خریدِ واقعی
     * انجام می‌داد. پاسخِ ۳۰۲ ِ خودِ شروعِ خرید گرفته و نشانی‌اش جعل می‌شود.
     */
    await page.route('**/subscription/checkout', async (route) => {
      const response = await route.fetch({ maxRedirects: 0 })
      const location = new URL(response.headers()['location'])
      expect(location.pathname).toMatch(/^\/checkout\/callback\//)
      location.searchParams.set('ref', 'FAKE-FORGED')
      location.searchParams.set('success', '1')
      // WebKit پاسخِ ۳۰۲ ِ ساختگی نمی‌پذیرد؛ refresh ِ متا همان ناوبری را در هر سه موتور می‌سازد
      await route.fulfill({
        status: 200,
        contentType: 'text/html',
        body: `<meta http-equiv="refresh" content="0;url=${location.toString()}">`,
      })
    })

    // هدایت‌های این زنجیره رویدادِ `response` نمی‌دهند؛ ناوبری‌های ثبت‌شده شمرده می‌شوند
    const visited: string[] = []
    page.on('framenavigated', (frame) => {
      if (frame === page.mainFrame()) visited.push(frame.url())
    })

    await submitCheckout(page, `مجتمع جعلی ${SUFFIX[testInfo.project.name] ?? 'چهار'}`)

    // سرور از وضعیتِ خودش هدایت می‌کند، نه از `success=1` ِ جعلی
    await expect
      .poll(() => visited.some((url) => /\/account\?checkout=failed/.test(url)))
      .toBe(true)
    expect(visited.some((url) => /checkout=success/.test(url))).toBe(false)
    await page.waitForLoadState('load')

    const user = await me(page)
    expect(user.accountState).toBe('initial')
    expect(user.complex).toBeNull()
  })

  test('پرداختِ تأییدشده: مجتمع، اشتراک و مدیر با هم؛ بازگشتِ دوباره بی‌اثر', async ({
    page,
  }, testInfo) => {
    const phone = BUYERS[testInfo.project.name] ?? BUYERS.chromium
    const complexName = `مجتمع خریدار ${SUFFIX[testInfo.project.name] ?? 'چهار'}`
    await signIn(page, phone)

    let callbackUrl = ''
    page.on('request', (request) => {
      if (request.url().includes('/checkout/callback/')) callbackUrl = request.url()
    })

    // بندِ ۳۴ ِ فایل ۲: پس از گذار همان حساب با همان پروفایل — نه حسابِ تازه
    const identity = (u: Awaited<ReturnType<typeof me>>) => [
      u.id,
      u.name,
      u.firstName,
      u.lastName,
      u.phone,
    ]
    const before = await me(page)
    expect(before.accountState).toBe('initial')
    expect([before.firstName, before.lastName, before.phone]).toEqual(['خریدار', 'سرتاسری', phone])

    await submitCheckout(page, complexName)
    await page.waitForURL(/\/dashboard\?checkout=success/)

    const user = await me(page)
    expect(identity(user), 'گذار به مدیر حساب یا پروفایل را عوض کرد').toEqual(identity(before))
    expect(user.accountState).toBe('member')
    expect(user.role).toBe('complex_admin')
    expect(user.complex?.name).toBe(complexName)

    // refresh ِ بازگشت: همان نتیجه، هیچ مجتمعِ دوم
    expect(callbackUrl).not.toBe('')
    await page.goto(callbackUrl)
    await expect(page).toHaveURL(/\/dashboard\?checkout=success/)
    expect((await me(page)).complex?.name).toBe(complexName)
  })
})
