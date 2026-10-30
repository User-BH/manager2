import { expect, test, type Page } from '@playwright/test'

/**
 * QR ِ ورودِ عمومی در مرورگرِ واقعی — مرحلهٔ ۱۰ · فاز ۵ (بندهای ۲۲، ۲۳ و ۲۵ ِ فایلِ معماری).
 *
 *   مدیر QR می‌سازد ← مهمان پیوندِ QR را باز می‌کند (نامِ مجتمع) ← وارد می‌شود ← درخواست می‌فرستد
 *   ← هنوز عضو نیست ← مدیر با واحد تأیید می‌کند ← داشبوردِ واحد
 *
 * هر موتور مجتمع، مدیر و درخواست‌کننده‌ی خودش را دارد (`E2eSeeder::QR_MANAGERS` / `QR_JOINERS`)، چون سفر QR
 * را روشن می‌کند و درخواست‌کننده را عضو. ورود با `fetch` است، همان دلیلِ سفرهای دیگر (پازلِ کشویی)؛ اینکه
 * پایانِ ورود به `/join` برمی‌گردد در `tests/js/publicJoin.test.tsx` سنجیده شده و اینجا کد از همان
 * `sessionStorage` ِ تب پس از ورود خوانده می‌شود.
 *
 * پیش‌نیاز: `DemoSeeder` و `E2eSeeder` (که QR را خاموش و درخواست‌کننده را حسابِ اولیه برمی‌گرداند).
 */

/** همان `E2eSeeder::QR_MANAGERS` */
const MANAGERS: Record<string, string> = {
  chromium: '09120000081',
  firefox: '09120000082',
  webkit: '09120000083',
}
/** همان `E2eSeeder::QR_JOINERS` */
const JOINERS: Record<string, string> = {
  chromium: '09120000071',
  firefox: '09120000072',
  webkit: '09120000073',
}
const INDEX: Record<string, number> = { chromium: 1, firefox: 2, webkit: 3 }
const JOINER_NAME = 'ثبت‌نام‌کننده‌ی سرتاسری'

/**
 * ⚠️ صفحه‌ی ورود هنگامِ بالا آمدن نشست را یک بار می‌پرسد (`authStore`) و اگر پاسخش **پس از** ورودِ همین
 * `fetch` برسد، کاربرِ واردشده را — درست — به داشبورد می‌فرستد؛ در WebKit همان ناوبری `fetch` ِ بعدیِ آزمون را
 * با «Load failed» برید (اجرای محلیِ ۱۰/۵). پس ورود فقط پس از رسیدنِ پاسخِ همان پرسش است.
 */
async function signIn(page: Page, phone: string) {
  await page.context().clearCookies()
  await Promise.all([
    page.waitForResponse((r) => /\/me$/.test(new URL(r.url()).pathname)),
    page.goto('/auth'),
  ])
  const status = await page.evaluate(async (phone) => {
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
      phone === '09120000001'
        ? await send('/api/admin/login', { username: 'sakena.admin', password: 'password' })
        : await send('/api/login', { phone: phone }).then(async (r) =>
            r.ok ? send('/api/login/verify', { code: (await r.json()).dev_code }) : r,
          )
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
          phone: string
          role: string
          accountState: string
          complex: { name: string } | null
        }
      }
    ).user
  })
}

/*
 * مدیر و درخواست‌کننده هر کدام زمینه‌ی مرورگرِ خودشان را دارند و هر کدام **یک بار** وارد می‌شوند: سقفِ ورودِ
 * هر IP بیست در دقیقه است و CI همه‌ی سفرها را پشتِ سرِ هم از یک IP می‌راند.
 */
test('QR ِ عمومی: اسکن ← درخواست ← هنوز عضو نیست ← تأییدِ مدیر ← داشبوردِ واحد', async ({
  page,
  browser,
}, testInfo) => {
  test.skip(!(testInfo.project.name in MANAGERS), 'فقط سه موتورِ دسکتاپ؛ هر کدام مجتمعِ خودش')
  const complexName = `مجتمع QR سرتاسری ${INDEX[testInfo.project.name]}`

  // ── مدیر QR می‌سازد؛ دو مسیر کنارِ هم و جدا ──
  const managerContext = await browser.newContext()
  const manager = await managerContext.newPage()
  await signIn(manager, MANAGERS[testInfo.project.name])
  await manager.goto('/residents')
  await manager.getByRole('button', { name: 'QR ِ عضویت' }).click()
  const dialog = manager.getByRole('dialog')
  await expect(dialog.getByRole('region', { name: 'QR ِ عمومی' })).toContainText('تأییدِ شما')
  await expect(dialog.getByRole('region', { name: 'دعوتِ مستقیم' })).toContainText('عضویتِ مستقیم')
  await dialog.getByRole('button', { name: 'ساختِ QR ِ عمومی' }).click()
  await expect(dialog.getByRole('img', { name: 'QR ِ ورودِ عمومیِ مجتمع' })).toBeVisible()
  const link = await dialog.getByRole('textbox', { name: 'پیوندِ QR' }).inputValue()
  expect(link).toMatch(/\/join#[A-Za-z0-9_-]{22}$/)

  // ── مهمانِ دارنده‌ی QR: فقط نامِ مجتمع، بی هیچ دسترسی ──
  await page.goto(link)
  await expect(page.getByRole('heading', { name: 'عضویت در مجتمع' })).toBeVisible()
  await expect(page.getByText(complexName)).toBeVisible()
  await expect(page.getByText(/اسکنِ QR به‌تنهایی عضویت نیست/)).toBeVisible()
  expect(new URL(page.url()).hash).toBe('')
  await expect(page.getByRole('link', { name: 'ثبت‌نام' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'ارسالِ درخواستِ عضویت' })).toHaveCount(0)

  // ── پس از ورود، همان تب به صفحه‌ی پیوستن برمی‌گردد و درخواست با کلیکِ خودش می‌رود ──
  await signIn(page, JOINERS[testInfo.project.name])
  await page.goto('/join')
  await expect(page.getByText(complexName)).toBeVisible()
  await page.getByRole('button', { name: 'ارسالِ درخواستِ عضویت' }).click()
  await expect(page.getByRole('heading', { name: 'درخواست فرستاده شد' })).toBeVisible()

  // صرفِ درخواست عضویت نیست: هنوز حسابِ اولیه، داشبوردِ اولیه
  const before = await me(page)
  expect(before).toMatchObject({ accountState: 'initial', complex: null })
  await page.goto('/dashboard')
  await expect(page.locator('[data-experience="registrant"]')).toBeAttached({ timeout: 15_000 })

  // ── مدیر در «درخواست‌های عضویت» با واحد تأیید می‌کند ──
  await manager.goto('/residents')
  await manager.getByLabel(`واحدِ ${JOINER_NAME}`).selectOption({ label: 'واحد QR-1' })
  await manager.getByRole('button', { name: 'تایید به‌عنوان مستاجر' }).click()
  await expect(manager.getByText('کاربر به مجتمع اضافه شد.')).toBeVisible()
  await managerContext.close()

  // ── همان نشستِ درخواست‌کننده حالا عضو است: داشبوردِ واحد، نه داشبوردِ اولیه ──
  await page.goto('/dashboard')
  const after = await me(page)
  expect(after).toMatchObject({
    accountState: 'member',
    role: 'tenant',
    complex: { name: complexName },
  })
  // بندِ ۳۳ ِ سندِ هسته (۱۲/۵): همان حساب و همان هویت — نه حسابِ تازه
  expect([after.id, after.name, after.phone], 'تأییدِ درخواست حساب یا هویت را عوض کرد').toEqual([
    before.id,
    before.name,
    before.phone,
  ])
  await expect(page.locator('aside').first()).toBeAttached({ timeout: 15_000 })
  await expect(page.locator('[data-experience="registrant"]')).toHaveCount(0)
})
