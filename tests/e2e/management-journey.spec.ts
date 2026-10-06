import { expect, test, type Locator, type Page } from '@playwright/test'

/**
 * سفرِ مدیریت در مرورگرِ واقعی — از ثبت‌نام تا خروج (مرحلهٔ ۵ · فاز ۸ · بندِ ۶۴).
 *
 * ─── 🔴 شکافی که اندازه‌گیری نشان داد ─────────────────────────────────────
 * از نُه سفرِ حیاتیِ بند (ثبت‌نام، ورود، پرداخت، کیف، اشتراک، ساختِ مجتمع،
 * ساختِ واحد، صدورِ شارژ، خروج)، فقط پرداخت و خروج در مرورگر طی می‌شدند.
 * ثبت‌نام و ساختِ مجتمع و واحد و صدورِ شارژ و اشتراک هیچ آزمونِ مرورگری
 * نداشتند.
 *
 * ─── چه چیزی واقعی است و چه چیزی نه ────────────────────────────────────────
 * - ثبت‌نام، ساختِ مجتمع، ساختِ واحد، صدورِ شارژ، اشتراک و خروج **با
 *   کلیک و تایپ در رابط** طی می‌شوند.
 * - ورود با `fetch` از داخلِ صفحه است، همان دلیلِ `financial-journey`:
 *   پازلِ کشویی در سه موتور شکننده است و پوششِ جدا دارد.
 * - قانونِ شارژ پیش‌شرط است و با API از داخلِ صفحه ساخته می‌شود؛ موضوعِ این
 *   آزمون صدورِ قبض است، نه فرمِ قانون.
 *
 * ─── پیش‌نیازِ محیط (CI همه را می‌سازد) ────────────────────────────────────
 * `DemoSeeder` (سوپرادمین)، `otp_disabled`، `APP_ENV=local` و `SMS_DRIVER=log`
 * (تا کدِ ثبت‌نام در پاسخ بیاید)، و برای اشتراک:
 * `SUBSCRIPTION_GATEWAY=sandbox` و `PAYMENT_SANDBOX_ENABLED=true`.
 *
 * ⚠️ هر موتور و هر اجرا شماره و مجتمعِ خودش را دارد: دیتابیسِ محلی بینِ
 * اجراها می‌ماند و شماره‌ی تکراری ثبت‌نام را رد می‌کند.
 */

const ENGINE: Record<string, string> = { chromium: '1', firefox: '2', webkit: '3' }
const RUN = String(Date.now()).slice(-6)

function phoneFor(prefix: '0935' | '0936', project: string): string {
  return `${prefix}${RUN}${ENGINE[project] ?? '9'}`
}

const PASSWORD = 'Secret123x'

/** فرمِ ورود و ثبت‌نام دو بار رسم می‌شود (دسکتاپ/موبایل)؛ فقط نسخه‌ی دیدنی. */
function visible(locator: Locator): Locator {
  return locator.filter({ visible: true }).first()
}

async function signIn(page: Page, phone: string, password: string): Promise<number> {
  await page.goto('/auth')

  return page.evaluate(
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
    { phone, password },
  )
}

async function postFromPage(page: Page, path: string, body: unknown): Promise<number> {
  return page.evaluate(
    async ({ path, body }) => {
      const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '')
      const res = await fetch(path, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-XSRF-TOKEN': xsrf,
        },
        body: JSON.stringify(body),
      })
      return res.status
    },
    { path, body },
  )
}

// ⚠️ ترتیب مهم است: مجتمعِ گامِ دوم، مدیرِ گامِ سوم را می‌سازد
test.describe.configure({ mode: 'serial' })

test.describe('سفرِ مدیریت', () => {
  test('ثبت‌نام با کدِ تأیید، و ورود با حسابِ تازه', async ({ page }, testInfo) => {
    const phone = phoneFor('0935', testInfo.project.name)

    await page.goto('/auth?tab=register')

    // نام و نام خانوادگیِ جدا (مرحلهٔ ۹ · فاز ۳)
    await visible(page.getByPlaceholder('مثلاً علی')).fill('ساکن')
    await visible(page.getByPlaceholder('مثلاً محمدی')).fill('سرتاسری')
    await visible(page.getByPlaceholder('۰۹xxxxxxxxx')).fill(phone)
    await visible(page.getByPlaceholder('حداقل ۸ نویسه')).fill(PASSWORD)
    await visible(page.getByPlaceholder('تکرار رمز عبور')).fill(PASSWORD)
    await visible(page.getByRole('checkbox')).check()

    const sent = page.waitForResponse(
      (r) => /\/register$/.test(new URL(r.url()).pathname) && r.request().method() === 'POST',
    )
    await visible(page.getByRole('button', { name: /دریافت کد تایید/ })).click()

    const response = await sent
    expect(response.status(), await response.text()).toBe(200)
    const { dev_code: code } = (await response.json()) as { dev_code: string | null }

    expect(code, 'کدِ آزمایشی نیامد؛ APP_ENV=local و SMS_DRIVER=log لازم است.').toBeTruthy()

    // 🔴 کد با تایپِ واقعی در خانه‌ها وارد می‌شود، نه با فراخوانیِ مستقیمِ API
    const verified = page.waitForResponse((r) =>
      /\/register\/verify$/.test(new URL(r.url()).pathname),
    )
    await visible(page.getByLabel('رقم 1 کد')).click()
    await page.keyboard.type(String(code))

    expect((await verified).status()).toBeLessThan(300)

    // حسابِ تازه واقعاً ساخته شده: با همان رمز وارد می‌شود
    expect(await signIn(page, phone, PASSWORD)).toBe(200)

    // و به داشبوردِ اولیه با نامِ ساخته‌شده از دو بخش و راهنما می‌رسد — بی درخواستِ تکمیلِ نام
    await page.goto('/dashboard')
    await expect(
      page.getByRole('heading', { level: 1, name: 'ساکن سرتاسری، خوش آمدید' }),
    ).toBeVisible()
    await expect(page.getByRole('region', { name: 'راهنما' })).toBeVisible()
    await expect(page.getByText('نام و نام خانوادگی‌تان را جدا ثبت کنید.')).toHaveCount(0)
  })

  test('سوپرادمین مجتمعِ تازه با مدیرش می‌سازد', async ({ page }, testInfo) => {
    expect(await signIn(page, '09120000001', 'password')).toBe(200)

    await page.goto('/system/complexes')
    await page.getByRole('button', { name: 'مجتمع جدید' }).click()

    const name = `مجتمعِ سرتاسری ${RUN}-${ENGINE[testInfo.project.name] ?? '9'}`
    await page.getByLabel('نام مجتمع').fill(name)
    await page.getByLabel('نوع مجتمع').selectOption('residential')
    await page.getByLabel('نام مدیر').fill('مدیرِ سرتاسری')
    await page.getByLabel('شماره موبایل مدیر').fill(phoneFor('0936', testInfo.project.name))
    await page.getByLabel('رمز عبور مدیر').fill(PASSWORD)
    await page.getByRole('button', { name: 'ساخت مجتمع' }).click()

    await expect(page.getByText(name).first()).toBeVisible()
  })

  test('مدیرِ تازه واحد می‌سازد و شارژِ دوره را صادر می‌کند', async ({ page }, testInfo) => {
    expect(await signIn(page, phoneFor('0936', testInfo.project.name), PASSWORD)).toBe(200)

    // ── ساختِ واحد در رابط ──
    await page.goto('/units')
    await page.getByRole('button', { name: 'واحد جدید' }).click()
    await page.getByLabel('شماره واحد').fill('101')
    await page.getByLabel('طبقه').fill('1')
    await page.getByLabel('متراژ (متر مربع)').fill('90')
    await page.getByLabel('تعداد ساکنین').fill('3')
    await page.getByRole('button', { name: 'ثبت واحد' }).click()

    await expect(page.getByRole('cell', { name: /^101/ }).first()).toBeVisible()

    // ── پیش‌شرط: یک قانونِ شارژِ ثابت ──
    expect(
      await postFromPage(page, '/api/v1/charge-rules', {
        name: 'شارژِ ثابتِ سرتاسری',
        type: 'fixed',
        category: 'owner',
        amount: 250000,
      }),
    ).toBeLessThan(300)

    // ── صدورِ قبض در رابط ──
    await page.goto('/bills')
    await page.getByRole('button', { name: /صدور قبوض دوره/ }).click()
    await page.getByRole('button', { name: 'صدور کن' }).click()

    await expect(page.getByText(/صادر شد/).first()).toBeVisible()
    await expect(
      page.getByText(/۲۵۰[,٬]۰۰۰/).first(),
      'قبضِ واحدِ تازه با مبلغِ قانون دیده نشد.',
    ).toBeVisible()
  })

  test('مدیر اشتراک را با درگاهِ آزمایشی می‌خرد', async ({ page }, testInfo) => {
    expect(await signIn(page, phoneFor('0936', testInfo.project.name), PASSWORD)).toBe(200)

    await page.goto('/account')

    const buyOnline = page.getByRole('button', { name: 'پرداخت آنلاین' }).first()
    await expect(
      buyOnline,
      'دکمه‌ی پرداختِ آنلاین نیست؛ SUBSCRIPTION_GATEWAY=sandbox و PAYMENT_SANDBOX_ENABLED=true لازم است.',
    ).toBeVisible()

    await buyOnline.click()
    await page.getByRole('button', { name: 'انتقال به درگاه' }).click()

    // فرمِ واقعیِ مرورگر → درگاهِ آزمایشی → بازگشت
    await page.waitForURL(/\/account\?checkout=/, { timeout: 20_000 })
    expect(new URL(page.url()).searchParams.get('checkout')).toBe('success')
  })

  test('خروج از منوی کاربر، و بسته‌شدنِ نشستِ سرور', async ({ page }, testInfo) => {
    expect(await signIn(page, phoneFor('0936', testInfo.project.name), PASSWORD)).toBe(200)

    /*
     * 🔴 بارشدن‌های سند شمرده می‌شوند. اجرای اولِ این تست در CI روی Firefox
     * با `NS_BINDING_ABORTED` شکست: خروج دو ناوبری به `/auth` می‌ساخت
     * (ProtectedRoute و UserMenu) و Firefox اولی را لغو می‌کرد. شمارش در
     * Chromium دو نقص نشان داد: `/auth` دو بار، و `/dashboard` هم دو بار —
     * نصبِ اولِ service worker صفحه را خودبه‌خود دوباره بار می‌کرد.
     */
    const documents: string[] = []
    page.on('request', (request) => {
      if (request.resourceType() === 'document') documents.push(new URL(request.url()).pathname)
    })

    await page.goto('/dashboard')
    // فرصتِ نصبِ service worker؛ نسخه‌ی خراب همین‌جا دوباره بار می‌کرد
    await page.waitForTimeout(2_000)
    await page.getByRole('button', { name: /مدیرِ سرتاسری/ }).click()
    await page.getByText('خروج از حساب').click()

    await expect(page).toHaveURL(/\/auth/, { timeout: 15_000 })
    await page.waitForLoadState('load')
    await page.waitForTimeout(1_000)

    expect(
      documents.filter((path) => path === '/dashboard'),
      'داشبورد دوباره بار شد',
    ).toHaveLength(1)
    expect(
      documents.filter((path) => path === '/auth'),
      'خروج بیش از یک ناوبری ساخت',
    ).toHaveLength(1)

    /*
     * ⚠️ `/me` عمداً عمومی است و برای مهمان ۲۰۰ با `user: null` می‌دهد (SPA با
     * همان می‌فهمد واردشده است یا نه). نسخه‌ی اولِ این تست ۴۰۱ می‌خواست و
     * قرمز شد — خطای تست بود، نه خروج. ادعای درست: کاربر تهی است و مسیرِ
     * محافظت‌شده بسته.
     */
    const after = await page.evaluate(async () => {
      const me = await fetch('/api/v1/me', {
        credentials: 'include',
        headers: { Accept: 'application/json' },
      })
      const units = await fetch('/api/v1/units', {
        credentials: 'include',
        headers: { Accept: 'application/json' },
      })
      return { user: ((await me.json()) as { user: unknown }).user, units: units.status }
    })

    expect(after.user).toBeNull()
    expect(after.units).toBe(401)
  })
})
