import { devices, expect, test, type Locator, type Page } from '@playwright/test'

/**
 * دکمه‌ی نصبِ اپلیکیشن در Hero (مرحلهٔ ۱۳ · فاز ۲؛ ایرادات ۱۱، بخشِ ۱.۷ — دوازده سناریو).
 *
 * ─── ⚠️ آنچه این‌جا سنجیدنی است و آنچه نیست ──────────────────────────────
 * Playwright نمی‌تواند PWA نصب کند و کرومیومش `beforeinstallprompt` ِ واقعی نمی‌فرستد (معیارِ تعامل و زمان). پس
 * رویداد **ساختگی** فرستاده می‌شود — همان شکلی که کروم می‌فرستد (`install-prompt.spec.ts`) — و آنچه سنجیده می‌شود رفتارِ
 * ما در برابرِ آن است. WebKit ِ Playwright پوششِ **موتورِ WebKit** است، نه تأییدِ Safari ِ واقعی روی دستگاهِ Apple؛
 * نصبِ واقعی روی کروم، اج، Safari ِ iOS و مک: IMPLEMENTATION READY — ENVIRONMENT VERIFICATION REQUIRED.
 */

const button = (page: Page) => page.getByTestId('hero-install')
const help = (page: Page) => page.getByTestId('hero-install-help')
const hero = (page: Page) =>
  page.locator('section').filter({ has: page.getByRole('heading', { level: 1 }) })

async function home(page: Page) {
  await page.goto('/')
  await page.waitForLoadState('networkidle')
}

/** همان رویدادی که کروم می‌فرستد؛ `prompt()` فقط ثبت می‌کند که صدا زده شد */
async function browserOffersInstall(page: Page, outcome: 'accepted' | 'dismissed') {
  await page.evaluate((choice) => {
    const w = window as unknown as { __prompted: number }
    w.__prompted = 0
    const event = Object.assign(new Event('beforeinstallprompt', { cancelable: true }), {
      prompt: () => {
        w.__prompted += 1
        return Promise.resolve()
      },
      userChoice: Promise.resolve({ outcome: choice }),
    })
    window.dispatchEvent(event)
  }, outcome)
}

const prompted = (page: Page) =>
  page.evaluate(() => (window as unknown as { __prompted?: number }).__prompted ?? 0)

function overlap(a: { x: number; y: number; width: number; height: number }, b: typeof a): boolean {
  return a.x < b.x + b.width && b.x < a.x + a.width && a.y < b.y + b.height && b.y < a.y + a.height
}

async function box(locator: Locator) {
  const b = await locator.boundingBox()
  expect(b, 'عنصر روی صفحه جایی ندارد').not.toBeNull()
  return b!
}

/** خطای JavaScript ِ صفحه و خطای کنسول، از همان لحظه‌ی بازشدن */
function collectErrors(page: Page): string[] {
  const errors: string[] = []
  page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`))
  page.on('console', (m) => {
    if (m.type() === 'error') errors.push(`console: ${m.text()}`)
  })
  return errors
}

test.describe('دکمه‌ی نصب در Hero', () => {
  // ── ۱ تا ۳: دسکتاپ، تبلت، موبایل ─────────────────────────────────────
  for (const size of [
    { name: 'دسکتاپ', width: 1280, height: 800 },
    { name: 'تبلت', width: 768, height: 1024 },
    { name: 'موبایل', width: 375, height: 812 },
  ]) {
    test(`${size.name}: درونِ Hero دیده می‌شود، با دو دکمه‌ی اصلی هم‌پوشانی ندارد و صفحه افقی سرریز نمی‌کند`, async ({
      page,
    }) => {
      await page.setViewportSize({ width: size.width, height: size.height })
      // هندسه‌ی نهایی، نه وسطِ انیمیشنِ ورودِ Hero (سایت با `MotionConfig reducedMotion="user"` به این پایبند است)
      await page.emulateMedia({ reducedMotion: 'reduce' })
      await home(page)

      await expect(button(page)).toBeVisible()
      await expect(button(page)).toHaveText(/نصب اپلیکیشن/)

      const own = await box(button(page))
      const area = await box(hero(page))
      expect(own.x).toBeGreaterThanOrEqual(area.x)
      expect(own.x + own.width).toBeLessThanOrEqual(area.x + area.width + 0.5)
      expect(own.y + own.height).toBeLessThanOrEqual(area.y + area.height)

      for (const name of [/شروع رایگان|ورود به داشبورد/, /مشاهده دمو/]) {
        const other = await box(hero(page).getByRole('button', { name }))
        expect(overlap(own, other), `دکمه‌ی نصب روی «${name}» افتاد`).toBe(false)
      }

      // هدفِ لمسی دست‌کم ۴۴ پیکسل و بی سرریزِ افقی
      expect(own.height).toBeGreaterThanOrEqual(44)
      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
      )
      expect(overflow).toBeLessThanOrEqual(0)
    })
  }

  // ── ۴: نصبِ موفق ───────────────────────────────────────────────────────
  test('نصبِ موفق: پنجره‌ی مرورگر باز می‌شود و «نصب شد» فقط پس از `appinstalled`', async ({
    page,
  }) => {
    await home(page)
    await browserOffersInstall(page, 'accepted')

    await button(page).click()
    expect(await prompted(page)).toBe(1)
    await expect(button(page)).toHaveText(/در انتظارِ مرورگر/)
    await expect(button(page)).not.toHaveText(/نصب شد/)

    await page.evaluate(() => window.dispatchEvent(new Event('appinstalled')))
    await expect(button(page)).toHaveText(/اپلیکیشن نصب شد/)
    await expect(button(page)).toBeDisabled()
  })

  // ── ۵: انصراف ─────────────────────────────────────────────────────────
  test('انصراف: هیچ ادعای نصب؛ کلیکِ دوباره پنجره‌ی مصرف‌شده را صدا نمی‌زند', async ({ page }) => {
    await home(page)
    await browserOffersInstall(page, 'dismissed')

    await button(page).click()
    await expect(help(page)).toContainText('نصب انجام نشد')
    await expect(button(page)).toHaveText(/نصب اپلیکیشن/)
    await expect(button(page)).toBeEnabled()

    await help(page).getByRole('button', { name: 'متوجه شدم' }).click()
    await button(page).click()
    expect(await prompted(page)).toBe(1)
  })

  // ── ۶: نبودِ رویدادِ نصب ──────────────────────────────────────────────
  test('بی رویداد در کرومیوم: دلیل و راهِ دیگر، نه پنجره‌ی ساختگی', async ({
    page,
    browserName,
  }) => {
    test.skip(
      browserName !== 'chromium',
      'WebKit با UA ِ سافاریِ مک راهنمای خودش را دارد (سناریوی ۷)',
    )

    await home(page)
    await button(page).click()

    await expect(help(page)).toContainText('نصبِ مستقیم را پیشنهاد نمی‌دهد')
    await expect(button(page)).toHaveAttribute('aria-expanded', 'true')
    await expect(page.getByText('اپلیکیشن نصب شد')).toHaveCount(0)
  })

  // ── ۷: راهنمای مرورگرِ بی نصبِ مستقیم ─────────────────────────────────
  test('سافاریِ مک (موتورِ WebKit): راهنمای مشروطِ «Add to Dock»', async ({
    page,
    browserName,
  }) => {
    test.skip(browserName !== 'webkit', 'فقط WebKit با UA ِ سافاریِ مک')

    await home(page)
    await button(page).click()
    await expect(help(page)).toContainText('اگر هنوز نصبش نکرده‌اید')
    await expect(help(page)).toContainText('Add to Dock')
  })

  test.describe('آیفون', () => {
    const { userAgent, viewport, deviceScaleFactor, isMobile, hasTouch } = devices['iPhone 13']
    test.use({ userAgent, viewport, deviceScaleFactor, isMobile, hasTouch })

    test('سافاریِ iOS (موتورِ WebKit): راهنمای «Add to Home Screen»؛ داخلِ اپ دکمه‌ای نیست', async ({
      page,
      browserName,
    }) => {
      test.skip(browserName !== 'webkit', 'نمایه‌ی آیفون فقط در WebKit')

      await page.addInitScript(() => {
        Object.defineProperty(navigator, 'standalone', { configurable: true, value: false })
      })
      await home(page)
      await button(page).click()
      await expect(help(page)).toContainText('Add to Home Screen')

      // ── ۸: پس از نصب — همان سافاری، داخلِ اپِ افزوده‌شده
      const inApp = await page.context().newPage()
      await inApp.addInitScript(() => {
        Object.defineProperty(navigator, 'standalone', { configurable: true, value: true })
      })
      await home(inApp)
      await expect(hero(inApp).getByRole('heading', { level: 1 })).toBeVisible()
      await expect(button(inApp)).toHaveCount(0)
    })
  })

  // ── ۸: رفتار پس از نصب (اپِ نصب‌شده با `display-mode: standalone`) ───────
  test('داخلِ اپِ نصب‌شده (`display-mode: standalone`): دکمه‌ای نیست', async ({ page }) => {
    await page.addInitScript(() => {
      const real = window.matchMedia.bind(window)
      window.matchMedia = (query: string) =>
        query === '(display-mode: standalone)'
          ? { ...real(query), matches: true, media: query }
          : real(query)
    })
    await home(page)

    await expect(hero(page).getByRole('heading', { level: 1 })).toBeVisible()
    await expect(button(page)).toHaveCount(0)
  })

  // ── ۹: حفظِ چیدمانِ Hero ──────────────────────────────────────────────
  test('چیدمانِ Hero دست نمی‌خورد: عنوان، متن، دو دکمه‌ی اصلی و تصویر همان‌جا که بی دکمه‌ی نصب بودند', async ({
    page,
  }) => {
    await page.setViewportSize({ width: 1280, height: 800 })
    await page.emulateMedia({ reducedMotion: 'reduce' })
    await home(page)

    const parts = [
      hero(page).getByRole('heading', { level: 1 }),
      hero(page).getByRole('button', { name: /شروع رایگان|ورود به داشبورد/ }),
      hero(page).getByRole('button', { name: /مشاهده دمو/ }),
      hero(page).getByRole('img').first(),
    ]
    const withButton = await Promise.all(parts.map(box))

    // همان صفحه، بی دکمه‌ی نصب: هیچ‌چیزِ دیگری نباید جابه‌جا شود
    await button(page).evaluate((el) => el.remove())
    const without = await Promise.all(parts.map(box))

    withButton.forEach((b, i) => {
      expect(Math.abs(b.x - without[i].x)).toBeLessThanOrEqual(1)
      expect(Math.abs(b.y - without[i].y)).toBeLessThanOrEqual(1)
    })
  })

  // ── ۱۰ و ۱۱: بی خطای JavaScript، و Service Worker همان که بود ───────────
  test('بی خطای JavaScript در همه‌ی حالت‌ها؛ دکمه Service Worker ثبت نمی‌کند و manifest همان است', async ({
    page,
  }) => {
    const errors = collectErrors(page)
    await home(page)

    // بی رویداد ← راهنما ← بستن ← رویداد ← انصراف ← کلیکِ دوباره ← رویدادِ تازه ← نصب ← appinstalled
    await button(page).click()
    await help(page).getByRole('button', { name: 'متوجه شدم' }).click()
    await browserOffersInstall(page, 'dismissed')
    await button(page).click()
    await button(page).click()
    await browserOffersInstall(page, 'accepted')
    await button(page).click()
    await page.evaluate(() => window.dispatchEvent(new Event('appinstalled')))
    await expect(button(page)).toHaveText(/اپلیکیشن نصب شد/)

    expect(errors).toEqual([])

    // صفحه‌های عمومی SW ثبت نمی‌کنند (معیارِ نصبِ کروم دیگر SW نمی‌خواهد)؛ دکمه هم این را عوض نمی‌کند
    const registrations = await page.evaluate(async () =>
      'serviceWorker' in navigator ? (await navigator.serviceWorker.getRegistrations()).length : 0,
    )
    expect(registrations).toBe(0)
    await expect(page.locator('link[rel="manifest"]')).toHaveAttribute(
      'href',
      '/manifest.webmanifest',
    )
    const manifest = await page.request.get('/manifest.webmanifest')
    expect(manifest.ok()).toBe(true)
    expect((await manifest.json()).start_url).toBe('/dashboard?source=pwa')
    const sw = await page.request.get('/sw.js')
    expect(sw.ok()).toBe(true)
  })

  // ── ۱۲: بی regression در صفحه‌های اصلی ────────────────────────────────
  test('کارتِ شناورِ پیشنهادِ نصب همچنان همان رویداد را دارد و پس از نصب از Hero دکمه‌ی مرده نمی‌ماند', async ({
    page,
  }) => {
    const errors = collectErrors(page)
    await home(page)
    await browserOffersInstall(page, 'dismissed')

    const card = page.getByTestId('install-prompt')
    await expect(card.getByRole('button', { name: 'نصب', exact: true })).toBeVisible()
    await button(page).click()
    /*
     * رویداد یک بار مصرف شد؛ کارت دیگر دکمه‌ی «نصب» ِ بی‌اثر نشان نمی‌دهد. (در WebKit با UA ِ سافاریِ مک کارت به
     * راهنمای «Add to Dock» ِ همان مرورگر برمی‌گردد — رویدادِ ساختگی آن‌جا واقعی نیست؛ دکمه‌ی مرده هم نیست.)
     */
    await expect(card.getByRole('button', { name: 'نصب', exact: true })).toHaveCount(0)
    expect(await prompted(page)).toBe(1)
    expect(errors).toEqual([])
  })
})
