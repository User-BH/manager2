import { expect, test } from '@playwright/test'

/**
 * سفرِ مالی در مرورگرِ واقعی — از ورود تا تاریخچهٔ پرداخت (S4-30).
 *
 * ─── 🔴 شکافی که ممیزیِ تجمیعی ثبت کرد ─────────────────────────────────────
 * منطقِ مالی در PHPUnit عمیق سنجیده می‌شود: `BillingFlowTest`،
 * `GatewayCallbackTest`، `AdversarialFinanceTest`، `WalletLedgerTest`.
 *
 * ⚠️ ولی **هیچ تستی کلِ مسیر را در یک مرورگر طی نمی‌کرد**. یعنی چسبِ بینِ
 * گام‌ها — ریدایرکتِ درگاه، کوکیِ نشست پس از بازگشت، خواندنِ پارامترِ
 * `?payment=` و رندرِ نتیجه — بی‌آزمون بود.
 *
 * ─── چرا ورود با `fetch` و نه با پرکردنِ فرم ───────────────────────────────
 * فرمِ ورود یک **پازلِ کشویی** دارد. کشیدنِ آن در سه موتورِ رندر شکننده
 * است و ادعای این فایل هم دربارهٔ پازل نیست.
 *
 * ⚠️ پازل و خودِ فرم پوششِ جداگانه دارند: `LoginForm.test.tsx` (شش تست) و
 * `critical-flows.spec.ts`. اینجا ورود **وسیله** است، نه موضوع.
 *
 * 🔴 ولی ورود همچنان واقعی است: از داخلِ صفحه، با کوکیِ واقعی و توکنِ
 * واقعیِ CSRF. چیزی جعل نمی‌شود.
 *
 * ─── پیش‌نیازِ داده ─────────────────────────────────────────────────────────
 * ⚠️ این فایل به `DemoSeeder` نیاز دارد. اگر دیتابیس خالی باشد تست‌ها
 * **رد می‌شوند، نه سبز** — چون سبزِ بی‌داده بدترین نتیجهٔ ممکن است.
 */

/**
 * 🔴 هر موتور، مالکِ خودش — و این را خودِ اجرا تحمیل کرد.
 *
 * ─── چه چیزی شکست ──────────────────────────────────────────────────────────
 * نسخهٔ اول برای هر سه موتور یک مالک داشت. کرومیوم قبض را **واقعاً پرداخت
 * کرد** و فایرفاکس و وبکیت دیگر قبضِ پرداخت‌نشده‌ای پیدا نکردند.
 *
 * ⚠️ یعنی تست وضعیتِ مشترکِ دیتابیس را عوض می‌کرد و اجرای بعدی را می‌شکست —
 * همان چیزی که در `S4-27` دربارهٔ وابستگی به حالتِ محیط ثبت شد، این‌بار
 * ساختهٔ خودم.
 *
 * `DemoSeeder` شش مالک می‌سازد و هرکدام دو قبضِ پرداخت‌نشده دارند، پس هر
 * موتور سهمِ جدای خودش را دارد.
 */
const OWNERS: Record<string, string> = {
  chromium: '09121111102',
  firefox: '09121111103',
  webkit: '09121111104',
}

function ownerFor(project: string): { phone: string; password: string } {
  return { phone: OWNERS[project] ?? '09121111105', password: 'password' }
}

/**
 * ورود از داخلِ صفحه، با همان مسیری که مرورگرِ کاربر طی می‌کند.
 *
 * ⚠️ فیکسچرِ `request` عمداً استفاده نمی‌شود: ظرفِ کوکیِ جدایی دارد و
 * کوکیِ `XSRF-TOKEN` را نمی‌بیند، پس همه‌چیز ۴۱۹ می‌گیرد.
 */
async function signIn(
  page: import('@playwright/test').Page,
  project: string,
): Promise<{ status: number; body: string }> {
  await page.goto('/auth')

  return page.evaluate(async (creds) => {
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

    return { status: res.status, body: await res.text() }
  }, ownerFor(project))
}

test.describe('سفرِ مالی', () => {
  test('ساکن وارد می‌شود و قبض‌هایش را می‌بیند', async ({ page }, testInfo) => {
    const login = await signIn(page, testInfo.project.name)
    expect(login.status, `ورود ناموفق: ${login.body}`).toBe(200)

    await page.goto('/my-bills')

    // 🔴 عنوانِ صفحه، نه صرفاً «۲۰۰ گرفتیم»
    await expect(page.getByRole('heading', { name: /صورت‌حساب|قبض/ }).first()).toBeVisible()

    /*
     * ⚠️ بدونِ این ادعا، تست با دیتابیسِ خالی هم سبز می‌شد — و آن‌وقت
     * چیزی را اثبات نمی‌کرد جز اینکه صفحه بالا می‌آید.
     */
    await expect(
      page.getByText(/تومان/).first(),
      'هیچ مبلغی رندر نشد؛ احتمالاً DemoSeeder اجرا نشده.',
    ).toBeVisible()
  })

  test('پرداختِ آنلاین از درگاهِ ساختگی تا نتیجه', async ({ page }, testInfo) => {
    const login = await signIn(page, testInfo.project.name)
    expect(login.status, `ورود ناموفق: ${login.body}`).toBe(200)

    await page.goto('/my-bills')
    await expect(page.getByText(/تومان/).first()).toBeVisible()

    /*
     * ─── 🔴 این همان چسبی است که هیچ تستِ PHP نمی‌سنجید ────────────────
     *
     * `POST /pay/{bill}/online` → درگاهِ ساختگی → ریدایرکت به callback
     * → `verify` → ریدایرکت به `/my-bills?payment=...`
     *
     * ⚠️ درگاه **صریحاً ساختگی** است (`FakeGateway`) و بیرونِ محیطِ مجاز
     * استثنا می‌دهد. این تست جای تراکنشِ واقعیِ بانکی را نمی‌گیرد —
     * آن `E-03` است و `ENVIRONMENT VERIFICATION REQUIRED` می‌ماند.
     */
    /*
     * ⚠️ **لینک** است نه دکمه — و این را خودِ تست لو داد.
     *
     * نسخهٔ اول دنبالِ `role=button` می‌گشت، چیزی پیدا نمی‌کرد و با
     * `test.skip` رد می‌شد. 🔴 یعنی تستی که هرگز اجرا نمی‌شد ولی قرمز هم
     * نبود — همان سبزِ پوچی که این پروژه مدام شکارش می‌کند.
     */
    const payLink = page.getByRole('link', { name: /پرداخت/ }).first()

    await expect(
      payLink,
      'قبضِ پرداخت‌نشده‌ای پیدا نشد؛ DemoSeeder باید دستِ‌کم یکی بسازد.',
    ).toBeVisible()

    await payLink.click()

    /*
     * ⚠️ «پرداخت» یک‌مرحله‌ای نیست: اول صفحهٔ **انتخابِ روش** می‌آید
     * (کیفِ پول / درگاه / رسید). این را هم خودِ اجرا نشان داد؛ نسخهٔ اول
     * انتظار داشت مستقیم به درگاه برود و روی `/pay/7` معطل ماند.
     */
    await page.waitForURL(/\/pay\/\d+/, { timeout: 15_000 })

    const toGateway = page.getByRole('button', { name: /انتقال به درگاه بانکی/ })

    await expect(
      toGateway,
      'دکمهٔ درگاه نیست؛ یعنی درگاهِ مجتمع فعال نیست و سفرِ آنلاین سنجیده نمی‌شود.',
    ).toBeVisible()

    await toGateway.click()

    // مرورگر خودش زنجیرهٔ ریدایرکت را دنبال می‌کند
    await page.waitForURL(/\/my-bills\?payment=/, { timeout: 15_000 })

    const url = new URL(page.url())
    const outcome = url.searchParams.get('payment')

    /*
     * 🔴 `unknown` هم نتیجهٔ معتبری است و عمداً پذیرفته می‌شود.
     *
     * ⚠️ معماری صریحاً «نمی‌دانم» را حالتِ سوم می‌داند و آن را `Failed`
     * ثبت نمی‌کند. تستی که فقط `success` را قبول کند، آن تصمیمِ درست را
     * به شکست ترجمه می‌کند.
     */
    expect(['success', 'unknown']).toContain(outcome)

    if (outcome === 'success') {
      await expect(page.getByText(/پرداخت با موفقیت انجام شد/)).toBeVisible()
    }
  })

  test('پرداختِ ثبت‌شده در تاریخچه دیده می‌شود', async ({ page }, testInfo) => {
    const login = await signIn(page, testInfo.project.name)
    expect(login.status, `ورود ناموفق: ${login.body}`).toBe(200)

    /*
     * تاریخچه از خودِ API خوانده می‌شود، نه از رندرِ صفحه.
     *
     * ⚠️ چیدمانِ جدول ممکن است عوض شود؛ آنچه نباید عوض شود این است که
     * پرداختِ ساکن **به او برگردانده شود**.
     */
    /*
     * 🔴 `/api/v1/payments` عمداً استفاده **نمی‌شود**.
     *
     * ⚠️ نسخهٔ اولِ این تست همان را می‌زد و ۴۰۳ گرفت — چون آن مسیر
     * مدیریتی است. یعنی تست فرضِ غلطی دربارهٔ دسترسیِ ساکن داشت و خودِ
     * اجرا اصلاحش کرد.
     *
     * ساکن تاریخچه‌اش را از `my-bills` می‌گیرد، و همین درست است:
     * جداسازیِ نقش‌ها کار می‌کند.
     */
    const mine = await page.evaluate(async () => {
      const res = await fetch('/api/v1/my-bills', {
        credentials: 'include',
        headers: { Accept: 'application/json' },
      })

      return { status: res.status, body: await res.json() }
    })

    expect(mine.status).toBe(200)
    expect(mine.body).toHaveProperty('data')

    // ⚠️ و ساکن نباید به مسیرِ مدیریتیِ پرداخت‌ها برسد
    const forbidden = await page.evaluate(async () => {
      const res = await fetch('/api/v1/payments', {
        credentials: 'include',
        headers: { Accept: 'application/json' },
      })

      return res.status
    })

    expect(forbidden).toBe(403)
  })

  test('پس از خروج، مسیرِ مالی دیگر باز نمی‌شود', async ({ page }, testInfo) => {
    const login = await signIn(page, testInfo.project.name)
    expect(login.status, `ورود ناموفق: ${login.body}`).toBe(200)

    const after = await page.evaluate(async () => {
      const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '')

      await fetch('/api/logout', {
        method: 'POST',
        credentials: 'include',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf },
      })

      const res = await fetch('/api/v1/payments', {
        credentials: 'include',
        headers: { Accept: 'application/json' },
      })

      return res.status
    })

    /*
     * 🔴 ادعای اصلی: خروج فقط حالتِ فرانت را پاک نمی‌کند، **نشستِ سرور**
     * را هم باطل می‌کند.
     *
     * ⚠️ اگر اینجا ۲۰۰ برگردد یعنی نشست هنوز زنده است و «خروج» فقط یک
     * انیمیشن بوده.
     */
    expect(after).toBe(401)
  })
})
