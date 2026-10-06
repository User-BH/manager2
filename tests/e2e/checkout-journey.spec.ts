import { expect, test, type Page } from '@playwright/test'

/**
 * خریدِ آنلاینِ اشتراک و ساختِ مجتمع توسطِ حسابِ اولیه (مرحلهٔ ۹ · فاز ۴).
 *
 * مسیرِ سرور در مرورگرِ واقعی: فرمِ خرید ← درگاهِ sandbox ← بازگشت ← تأییدِ سرور
 * ← در یک تراکنش مجتمع + اشتراک + مدیر ← داشبوردِ مدیر. رابطِ انتخابِ پلن برای
 * حسابِ اولیه در ۹/۵ ساخته می‌شود؛ اینجا همان فرمِ پنهانی که صفحه‌ی حساب
 * می‌فرستد ساخته و فرستاده می‌شود.
 *
 * پیش‌نیاز: `SUBSCRIPTION_GATEWAY=sandbox` و `PAYMENT_SANDBOX_ENABLED=true` (مثلِ CI)
 * و `E2eSeeder::BUYERS`. هر موتور خریدارِ خودش را دارد.
 */

/** همان `E2eSeeder::BUYERS` */
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
        user: { accountState: string; role: string; complex: { name: string } | null }
      }
    ).user
  })
}

/** همان فرمی که صفحه‌ی حساب به `checkoutAction` می‌فرستد */
async function submitCheckout(page: Page, complexName: string) {
  await page.goto('/account')
  await page.evaluate(async (complexName) => {
    const { csrfToken } = (await (
      await fetch('/api/csrf-token', { credentials: 'include' })
    ).json()) as {
      csrfToken: string
    }
    const form = document.createElement('form')
    form.method = 'POST'
    form.action = '/subscription/checkout'
    for (const [name, value] of Object.entries({
      _token: csrfToken,
      plan: 'basic',
      complex_name: complexName,
      complex_type: 'residential',
    })) {
      const input = document.createElement('input')
      input.type = 'hidden'
      input.name = name
      input.value = value
      form.appendChild(input)
    }
    document.body.appendChild(form)
    form.submit()
  }, complexName)
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

    await submitCheckout(page, `مجتمع جعلی ${testInfo.project.name}`)

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
    const complexName = `مجتمع خریدار ${testInfo.project.name}`
    await signIn(page, phone)

    let callbackUrl = ''
    page.on('request', (request) => {
      if (request.url().includes('/checkout/callback/')) callbackUrl = request.url()
    })

    await submitCheckout(page, complexName)
    await page.waitForURL(/\/dashboard\?checkout=success/)

    const user = await me(page)
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
