import { devices, expect, test, type Page } from '@playwright/test'

/**
 * پیشنهادِ نصب در مرورگرِ واقعی (مرحلهٔ ۸ · فاز ۲ · بندِ ۱).
 *
 * ─── ⚠️ آنچه این‌جا سنجیدنی است و آنچه نیست ──────────────────────────────
 * Playwright نمی‌تواند PWA نصب کند و `display-mode` را هم شبیه‌سازی نمی‌کند؛
 * کرومیومش هم `beforeinstallprompt` ِ واقعی نمی‌فرستد (معیارِ تعامل و زمان).
 * پس رویداد **ساختگی** فرستاده می‌شود — همان شکلی که کروم می‌فرستد — و آنچه
 * سنجیده می‌شود رفتارِ ما در برابرِ آن است: کارت می‌آید، با بارگذاریِ دوباره
 * برنمی‌گردد، بستن می‌ماند. «داخلِ اپ هیچ» و قواعدِ هر مرورگر در
 * `installState.test.ts` است. رفتار روی اپِ نصب‌شده‌ی واقعی:
 * IMPLEMENTATION READY — ENVIRONMENT VERIFICATION REQUIRED.
 *
 * WebKit ِ Playwright نمایه‌ی «Desktop Safari» را با UA ِ سافاریِ مک می‌سازد،
 * پس همان‌جا راهنمای «Add to Dock» واقعاً سنجیده می‌شود. `navigator.standalone`
 * ِ iOS در آن نیست (سافاریِ واقعی دارد)؛ برای راهنمای iOS تعریف می‌شود.
 */

const card = (page: Page) => page.getByTestId('install-prompt')

/** همان رویدادی که کروم می‌فرستد؛ `prompt()` فقط ثبت می‌کند که صدا زده شد */
async function browserOffersInstall(page: Page) {
  await page.evaluate(() => {
    const event = Object.assign(new Event('beforeinstallprompt', { cancelable: true }), {
      prompt: () => {
        ;(window as unknown as { __prompted: boolean }).__prompted = true
        return Promise.resolve()
      },
      userChoice: Promise.resolve({ outcome: 'dismissed' as const }),
    })
    window.dispatchEvent(event)
  })
}

async function home(page: Page) {
  await page.goto('/')
  await page.waitForLoadState('networkidle')
}

test.describe('پیشنهادِ نصب', () => {
  test('مرورگر پیشنهاد داد: دکمه‌ی «نصب» همان رویداد را صدا می‌زند', async ({ page }) => {
    await home(page)
    await browserOffersInstall(page)

    await expect(card(page)).toBeVisible()
    await expect(card(page)).toContainText('نصبِ اپلیکیشنِ ساکنا')
    await card(page).getByRole('button', { name: 'نصب', exact: true }).click()
    expect(
      await page.evaluate(() => (window as unknown as { __prompted?: boolean }).__prompted),
    ).toBe(true)
  })

  test('بی‌بستن هم با بارگذاریِ دوباره برنمی‌گردد', async ({ page }) => {
    await home(page)
    await browserOffersInstall(page)
    await expect(card(page)).toBeVisible()

    await page.reload()
    await page.waitForLoadState('networkidle')
    await browserOffersInstall(page)
    await page.waitForTimeout(2500)
    await expect(card(page)).toHaveCount(0)
  })

  test('بستن به صفحه‌های دیگر هم می‌رسد', async ({ page }) => {
    await home(page)
    await browserOffersInstall(page)
    await card(page).getByRole('button', { name: 'بستنِ پیشنهادِ نصب' }).click()
    await expect(card(page)).toHaveCount(0)

    // روزها بعد: فاصله‌ی تکرار گذشته — فقط «بستن» باید جلویش را بگیرد
    await page.evaluate(() =>
      localStorage.setItem('sakena:install-state', JSON.stringify({ lastShownAt: 0 })),
    )

    await page.goto('/support')
    await page.waitForLoadState('networkidle')
    await browserOffersInstall(page)
    await page.waitForTimeout(2500)
    await expect(card(page)).toHaveCount(0)
  })

  test('سافاریِ مک: راهنمای مشروطِ «Add to Dock»، بی‌دکمه‌ی ساختگیِ نصب', async ({
    page,
    browserName,
  }) => {
    test.skip(browserName !== 'webkit', 'فقط WebKit با UA ِ سافاریِ مک')

    await home(page)
    await expect(card(page)).toBeVisible({ timeout: 8000 })
    await expect(card(page)).toContainText('اگر هنوز نصبش نکرده‌اید')
    await expect(card(page)).toContainText('Add to Dock')
    await expect(card(page).getByRole('button', { name: 'نصب', exact: true })).toHaveCount(0)

    // راهنما هم با بارگذاریِ دوباره برنمی‌گردد
    await page.reload()
    await page.waitForTimeout(5000)
    await expect(card(page)).toHaveCount(0)
  })

  test.describe('آیفون', () => {
    // بی `defaultBrowserType`: آن کارگرِ تازه می‌خواهد و در describe مجاز نیست
    const { userAgent, viewport, deviceScaleFactor, isMobile, hasTouch } = devices['iPhone 13']
    test.use({ userAgent, viewport, deviceScaleFactor, isMobile, hasTouch })

    test('سافاریِ iOS: راهنمای «Add to Home Screen»؛ داخلِ اپ هیچ', async ({
      page,
      browserName,
    }) => {
      test.skip(browserName !== 'webkit', 'نمایه‌ی آیفون فقط در WebKit')

      await page.addInitScript(() => {
        // سافاریِ واقعی بیرون از اپ `false` دارد؛ WebKit ِ Playwright هیچ
        Object.defineProperty(navigator, 'standalone', { configurable: true, value: false })
      })
      await home(page)
      await expect(card(page)).toBeVisible({ timeout: 8000 })
      await expect(card(page)).toContainText('Add to Home Screen')
      await expect(card(page).getByRole('button', { name: 'نصب', exact: true })).toHaveCount(0)

      // همان سافاری، داخلِ اپِ افزوده‌شده
      const inApp = await page.context().newPage()
      await inApp.addInitScript(() => {
        Object.defineProperty(navigator, 'standalone', { configurable: true, value: true })
        localStorage.clear()
      })
      await inApp.goto('/')
      await inApp.waitForTimeout(5000)
      await expect(card(inApp)).toHaveCount(0)
    })
  })
})
