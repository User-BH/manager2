import { expect, test, type Page } from '@playwright/test'

/**
 * پیوندِ «قوانین و مقررات» در ثبت‌نام (مرحلهٔ ۷ · فاز ۶).
 *
 * 🔴 پیوندِ قبلی `target="_blank"` بود. با ساکنای نصب‌شده، کروم پیوندی را که
 * ناوبریِ تازه می‌سازد و داخلِ `scope`ِ manifest است به پنجره‌ی اپ می‌برد،
 * و در خودِ اپِ نصب‌شده پنجره‌ی اپیِ بی‌نوارِ مرورگر باز می‌شد — قوانین در
 * «وب‌اپ» باز می‌شد نه در وبِ عمومی.
 *
 * ⚠️ نصبِ PWA در Playwright ممکن نیست، پس خودِ «ربودنِ پیوند» اینجا
 * بازتولیدشدنی نیست. چیزی که سنجیده می‌شود **ریشه** است: کلیکِ ساده دیگر هیچ
 * ناوبری‌ای نمی‌سازد — نه تبِ تازه، نه پنجره، نه خروج از صفحه — پس چیزی
 * برای ربودن نمی‌ماند. به‌اضافه‌ی رفتارِ آدرسِ مستقیم، بارگذاریِ دوباره و
 * بازگشت/جلو که ممیزی صریحاً خواسته بود.
 */

const REGISTER = '/auth?tab=register'

function termsLink(page: Page) {
  return page.getByRole('link', { name: 'قوانین و مقررات استفاده از پنل' }).locator('visible=true')
}

function dialog(page: Page) {
  return page.getByRole('dialog', { name: 'قوانین و مقررات' })
}

test.describe('قوانین در ثبت‌نام', () => {
  test('کلیکِ ساده هیچ ناوبری‌ای نمی‌سازد و قوانین همین‌جا باز می‌شود', async ({
    page,
    context,
  }) => {
    await page.goto(REGISTER)

    const popups: string[] = []
    context.on('page', (p) => popups.push(p.url()))
    let navigated = false
    page.on('framenavigated', (frame) => {
      if (frame === page.mainFrame() && !frame.url().includes('/auth')) navigated = true
    })

    await termsLink(page).click()

    await expect(dialog(page)).toBeVisible()
    await expect(dialog(page)).toContainText('پذیرش شرایط')
    // متنِ پررنگ پررنگ است، نه ستاره‌های خام
    await expect(dialog(page).locator('strong').first()).toBeVisible()
    await expect(dialog(page)).not.toContainText('**')

    expect(popups, 'تب یا پنجره‌ی تازه باز شد').toEqual([])
    expect(navigated, 'صفحه عوض شد').toBe(false)
    expect(new URL(page.url()).pathname).toBe('/auth')
  })

  test('فرمِ نیمه‌پر و تیکِ قوانین با باز و بسته‌کردن دست نمی‌خورند', async ({ page }) => {
    await page.goto(REGISTER)

    const name = page.getByLabel('نام', { exact: true }).locator('visible=true')
    await name.fill('مریم')
    const checkbox = page.getByRole('checkbox').locator('visible=true')
    await expect(checkbox).not.toBeChecked()

    await termsLink(page).click()
    await expect(dialog(page)).toBeVisible()
    await page.getByRole('button', { name: 'بازگشت به ثبت‌نام' }).click()

    await expect(dialog(page)).toBeHidden()
    await expect(name).toHaveValue('مریم')
    // کلیک روی پیوندِ داخلِ برچسب نباید تیک را بزند — پذیرش باید آگاهانه باشد
    await expect(checkbox).not.toBeChecked()
  })

  test('بازگشت پنجره را می‌بندد و در صفحه می‌ماند؛ جلو دوباره بازش می‌کند', async ({ page }) => {
    await page.goto('/')
    await page.goto(REGISTER)

    await termsLink(page).click()
    await expect(dialog(page)).toBeVisible()
    expect(new URL(page.url()).searchParams.get('terms')).toBe('1')

    await page.goBack()
    await expect(dialog(page)).toBeHidden()
    await expect(page).toHaveURL(/\/auth\?tab=register$/)

    await page.goForward()
    await expect(dialog(page)).toBeVisible()
  })

  test('بستن با دکمه گامِ تاریخچه‌اش را برمی‌دارد', async ({ page }) => {
    await page.goto('/')
    await page.goto(REGISTER)

    await termsLink(page).click()
    // ⚠️ پنجره تنبل است؛ Escape پیش از رسیدنش به هیچ‌جا نمی‌رسید و «پنهان»
    // پوچ سبز می‌شد — همان خطایی که نسخه‌ی اولِ همین تست داشت
    await expect(dialog(page)).toBeVisible()
    /*
     * ⚠️ دیده‌شدن کافی نیست: شنونده‌ی Escapeِ `Modal` در `useEffect` است و پس
     * از نقاشی وصل می‌شود. Playwright در همان چند میلی‌ثانیه Escape می‌زد و
     * در WebKit ۳ بار از ۱۰ پنجره باز می‌ماند. تله‌ی فوکوس در همان مرحله
     * فوکوس را به «بستن» می‌برد — پس فوکوسِ آنجا یعنی شنونده هم وصل است.
     * (و دسترس‌پذیریِ پنجره را هم می‌سنجد.)
     */
    await expect(dialog(page).getByRole('button', { name: 'بستن' })).toBeFocused()
    await page.keyboard.press('Escape')
    await expect(dialog(page)).toBeHidden()
    // ⚠️ آدرس با انتظار، نه `page.url()`ِ لحظه‌ای: رویدادِ ناوبریِ درون‌سندی
    // ممکن است کمی پس از تغییرِ DOM برسد
    await expect(page).not.toHaveURL(/terms=1/)

    // یک «بازگشت» به صفحه‌ی قبل می‌رود، نه به پنجره‌ی بسته
    await page.goBack()
    await expect(page).toHaveURL(/\/$/)
  })

  test('آدرسِ مستقیم و بارگذاریِ دوباره پنجره را باز نشان می‌دهند', async ({ page }) => {
    await page.goto('/')
    await page.goto(`${REGISTER}&terms=1`)
    await expect(dialog(page)).toBeVisible()

    await page.reload()
    await expect(dialog(page)).toBeVisible()

    // بستن پارامتر را پاک می‌کند بی‌آنکه کاربر را از سایت بیرون ببرد
    await page.getByRole('button', { name: 'بستن' }).click()
    await expect(dialog(page)).toBeHidden()
    await expect(page).toHaveURL(/\/auth\?tab=register$/)
    await page.goBack()
    await expect(page).toHaveURL(/\/$/)
  })

  test('صفحه‌ی عمومیِ قوانین با آدرسِ مستقیم همچنان در دسترس است', async ({ page }) => {
    const response = await page.goto('/support?topic=terms')
    expect(response?.status()).toBe(200)
    await expect(page.getByText('پذیرش شرایط').first()).toBeVisible()
  })

  test.describe('موبایل', () => {
    test.use({ viewport: { width: 390, height: 780 } })

    test('پنجره روی موبایل هم باز می‌شود و صفحه افقی سرریز نمی‌شود', async ({ page }) => {
      await page.goto(REGISTER)
      await termsLink(page).click()

      await expect(dialog(page)).toBeVisible()
      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
      )
      expect(overflow).toBeLessThanOrEqual(0)
    })
  })
})
