import { expect, test, type Browser, type Locator, type Page } from '@playwright/test'

/**
 * نوارِ بالا و پنلِ جست‌وجو در مرورگرِ واقعی — مرحلهٔ ۱۳ · فاز ۴ (ایرادات ۱۱، بندهای ۲.۴ تا ۲.۹ و ۲.۱۱؛ ایرادات ۱۰،
 * بندهای ۵۳ تا ۶۳).
 *
 * چهار داشبورد در چهار عرض (۱۲۸۰، ۱۰۲۴، ۷۶۸، ۳۷۵): ابزارهای لازم دیده می‌شوند و ممنوع‌ها نه، هیچ دو آیکونی روی هم
 * نمی‌افتند، نوار سرریزِ افقی نمی‌سازد. در عرضِ کم کادرِ جست‌وجو آیکون می‌شود و پنلِ زیرِ سربرگ باز می‌شود — روی محتوا،
 * نه پشتش — و نتیجه در محتوای اصلی می‌آید. سوپر ادمینِ بی مجتمعِ برگزیده با جست‌وجو هیچ داده‌ی مجتمعی نمی‌گیرد.
 *
 * پیش‌نیاز: `DemoSeeder`، `E2eSeeder` (`TOP_BAR_INITIAL`، `TOP_BAR_UNITS`).
 */

const INITIAL: Record<string, string> = {
  chromium: '09120000121',
  firefox: '09120000122',
  webkit: '09120000123',
}
const UNIT: Record<string, string> = {
  chromium: '09120000124',
  firefox: '09120000125',
  webkit: '09120000126',
}
const MANAGER = '09120000000'
const SUPER_ADMIN = '09120000001'

const WIDTHS = [1280, 1024, 768, 375] as const
/** گوشی‌های باریک‌تر — آدمک و منویش در ‎۳۶۰px از لبه‌ی چپ بیرون می‌زدند (۲۰۲۶-۱۰-۲۹، بندِ ۸) */
const MEMBER_WIDTHS = [...WIDTHS, 360, 320] as const

async function signedIn(browser: Browser, phone: string): Promise<Page> {
  const page = await (await browser.newContext()).newPage()
  await page.goto('/auth')
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
  return page
}

/** ابزارهای دیده‌شده‌ی نوار به نام — همان که کاربر و صفحه‌خوان می‌بینند */
function tools(bar: Locator) {
  return {
    searchBox: bar.getByRole('searchbox', { name: 'جستجو در واحدها، ساکنین و قبوض' }),
    searchToggle: bar.locator('[aria-controls="top-bar-search-panel"]'),
    recent: bar.getByRole('link', { name: /^جستجوهای اخیر/ }),
    calculator: bar.getByRole('link', { name: 'ماشین حساب' }),
    calendar: bar.getByRole('link', { name: 'تقویم' }),
    notifications: bar.getByRole('button', { name: /اعلان/ }),
    theme: bar.getByRole('button', { name: /حالت (تاریک|روشن)/ }),
  }
}

/** هیچ سرریزِ افقی، و هیچ دو کنترلِ دیده‌شده‌ی نوار روی هم */
async function assertTidy(page: Page, width: number) {
  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth - window.innerWidth,
  )
  expect(overflow, `${width}px: سرریزِ افقی`).toBeLessThanOrEqual(0)

  const boxes = await page
    .getByRole('banner')
    .locator('a:visible, button:visible, input:visible')
    .evaluateAll((els) =>
      els
        .map((el) => el.getBoundingClientRect())
        .filter((r) => r.width > 0 && r.height > 0)
        .map((r) => ({ l: r.left, r: r.right, t: r.top, b: r.bottom })),
    )
  for (let i = 0; i < boxes.length; i++) {
    for (let j = i + 1; j < boxes.length; j++) {
      const [a, b] = [boxes[i], boxes[j]]
      const overlapX = Math.min(a.r, b.r) - Math.max(a.l, b.l)
      const overlapY = Math.min(a.b, b.b) - Math.max(a.t, b.t)
      // ۱px برای لبه‌ی مشترکِ کنترل‌های چسبیده؛ کنترلِ درونِ کنترلِ دیگر (×ِ کادرِ جست‌وجو) جدا سنجیده نمی‌شود
      const nested =
        (a.l <= b.l && a.r >= b.r && a.t <= b.t && a.b >= b.b) ||
        (b.l <= a.l && b.r >= a.r && b.t <= a.t && b.b >= a.b)
      if (!nested)
        expect(overlapX > 1 && overlapY > 1, `${width}px: دو کنترلِ نوار روی هم`).toBe(false)
    }
  }
}

test.describe.configure({ mode: 'serial' })

for (const role of ['unit', 'manager', 'platform'] as const) {
  test(`${role}: ابزارها در چهار عرض، و پنلِ جست‌وجوی عرضِ کم`, async ({ browser }, testInfo) => {
    test.setTimeout(90_000)
    const phone = { unit: UNIT[testInfo.project.name], manager: MANAGER, platform: SUPER_ADMIN }[
      role
    ]
    test.skip(phone === undefined, 'حساب‌های نوار فقط برای سه موتورِ دسکتاپ ساخته شده است.')
    const page = await signedIn(browser, phone)
    const errors: string[] = []
    page.on('pageerror', (error) => errors.push(error.message))
    await page.goto('/dashboard')
    const bar = page.getByRole('banner')
    const t = tools(bar)

    for (const width of MEMBER_WIDTHS) {
      // ⚠️ اعلان با hover باز می‌شود؛ نشانگر جای کلیکِ قبلی مانده و با تغییرِ عرض زنگ زیرِ آن می‌لغزید و پنلش باز می‌شد
      await page.mouse.move(1, 790)
      await page.setViewportSize({ width, height: 800 })
      const account = bar.getByRole('button', { name: /^حساب کاربری/ })
      await expect(t.notifications).toBeVisible()
      await expect(t.calendar).toBeVisible()
      await expect(account).toBeVisible()
      /*
       * ۲۰۲۶-۱۰-۳۰ (بندِ ۱): همه‌ی ابزارها در هر عرض در خودِ نوار — ماشین‌حساب و تم هم؛ زیرِ ‎۶۴۰px کمی کوچک‌تر. منوی آدمک
       * هم درونِ صفحه باز می‌شود. کادرِ کامل و «اخیر» از ‎۱۰۲۴px؛ پایین‌تر آیکون و پنل (همان «اخیر» را دارد).
       */
      await expect(t.theme).toBeVisible()
      await expect(t.calculator).toBeVisible()
      await account.click()
      const menu = (await page
        .getByRole('button', { name: 'خروج از حساب' })
        .locator('xpath=..')
        .boundingBox())!
      expect(menu.x, `${width}px: منوی آدمک از لبه‌ی چپ بیرون زد`).toBeGreaterThanOrEqual(0)
      expect(menu.x + menu.width, `${width}px: منو از لبه‌ی راست بیرون زد`).toBeLessThanOrEqual(
        width,
      )
      await expect(bar.getByRole('button', { name: 'ماشین حساب' })).toHaveCount(0)
      await account.click()
      // ⚠️ منو با انیمیشن بسته می‌شود و گزینه‌هایش تا پایانِ آن درونِ سربرگ دیده می‌شوند؛ سنجشِ «روی هم» مالِ خودِ نوار است
      await expect(page.getByRole('button', { name: 'خروج از حساب' })).toBeHidden()
      if (width >= 1024) {
        await expect(t.searchBox).toBeVisible()
        await expect(t.recent).toBeVisible()
        await expect(t.searchToggle).toBeHidden()
      } else {
        await expect(t.searchBox).toBeHidden()
        await expect(t.recent).toBeHidden()
        await expect(t.searchToggle).toBeVisible()
      }
      // آدمک تمام‌قد درونِ صفحه
      const box = (await account.boundingBox())!
      expect(box.x, `${width}px: آدمک بیرون از صفحه`).toBeGreaterThanOrEqual(0)
      await assertTidy(page, width)
    }
    await page.setViewportSize({ width: 375, height: 800 })

    // ── منوی آدمک در لبه‌ی صفحه بیرون نمی‌زند (بندِ ۲.۹) ──
    const account = bar.getByRole('button', { name: /^حساب کاربری/ })
    await account.click()
    const signOut = page.getByRole('button', { name: 'خروج از حساب' })
    await expect(signOut).toBeVisible()
    const menu = (await signOut.locator('xpath=..').boundingBox())!
    expect(menu.x, 'منو از لبه‌ی چپ بیرون زد').toBeGreaterThanOrEqual(0)
    expect(menu.x + menu.width, 'منو از لبه‌ی راست بیرون زد').toBeLessThanOrEqual(375)
    await account.click()
    await expect(signOut).toBeHidden()

    // ── عرضِ کم: پنل زیرِ سربرگ، روی محتوا؛ ورود با انیمیشن (ایرادات ۱۰ بندِ ۵۶، DoD ِ بندِ ۱۱۰) ──
    // کدری ِ پنل در هر فریم از لحظه‌ی کلیک: باید از کمتر از ۱ شروع شود و به ۱ برسد — ظاهرشدنِ ناگهانی رد است
    const frames = await t.searchToggle.evaluate(
      (toggle: HTMLElement) =>
        new Promise<number[]>((resolve) => {
          const seen: number[] = []
          const started = performance.now()
          toggle.click()
          const sample = () => {
            const el = document.getElementById(toggle.getAttribute('aria-controls') ?? '')
            if (el) seen.push(Number(getComputedStyle(el).opacity))
            if (seen.at(-1) === 1 || performance.now() - started > 2000) resolve(seen)
            else requestAnimationFrame(sample)
          }
          requestAnimationFrame(sample)
        }),
    )
    expect(
      frames.some((o) => o < 1),
      `پنل بی انیمیشن ظاهر شد: ${frames.join('، ')}`,
    ).toBe(true)
    expect(frames.at(-1), 'انیمیشنِ ورود به کدریِ کامل نرسید').toBe(1)
    const panel = page.getByRole('region', { name: 'جستجو' })
    await expect(panel).toBeVisible()
    const header = (await bar.boundingBox())!
    // جای **نهایی**، پس از انیمیشنِ ورود (لغزشِ ۸px از بالا) — نه لحظه‌ی ظاهرشدن
    await expect
      .poll(async () => (await panel.boundingBox())?.y ?? -1, { message: 'پنل زیرِ سربرگ است' })
      .toBeGreaterThanOrEqual(header.y + header.height - 1)
    const onTop = await panel.evaluate((el) => {
      const r = el.getBoundingClientRect()
      const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2)
      return hit !== null && el.contains(hit)
    })
    expect(onTop, 'پنل پشتِ محتوا نیست').toBe(true)
    await assertTidy(page, 375)
    // متنِ راهنمای ورودی بریده نمی‌شود (بندِ ۶۳) — نسخه‌ی اول در ۳۷۵px بریده بود
    const clipped = await panel.getByRole('searchbox').evaluate((el: HTMLInputElement) => {
      const style = getComputedStyle(el)
      const context = document.createElement('canvas').getContext('2d')!
      context.font = `${style.fontWeight} ${style.fontSize} ${style.fontFamily}`
      const room = el.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight)
      return context.measureText(el.placeholder).width > room
    })
    expect(clipped, 'placeholder ِ پنل بریده است').toBe(false)

    // جست‌وجو ← نتیجه در محتوای اصلی، پنل بسته
    await panel.getByRole('searchbox').fill('۱۰')
    await panel.getByRole('button', { name: 'جستجو', exact: true }).click()
    await expect(page).toHaveURL(/\/search\?q=/)
    await expect(panel).toBeHidden()
    await expect(
      page.locator('#main-content').getByRole('heading', { name: /نتایج جستجو/ }),
    ).toBeVisible()

    if (role === 'platform') {
      // سوپر ادمینِ بی مجتمعِ برگزیده: هیچ داده‌ی مجتمعی، و راهنمای برگزیدنِ مجتمع
      await expect(page.locator('#main-content').getByText(/مدیریت مجتمع‌ها/)).toBeVisible()
    }

    // آخرین جست‌وجو در پنل، و بستن با Escape
    await t.searchToggle.click()
    await expect(panel.getByRole('list', { name: 'آخرین جستجوها' }).getByText('۱۰')).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(panel).toBeHidden()

    expect(errors, 'خطای JavaScript').toEqual([])
    await page.context().close()
  })
}

test('اولیه: آدمک، اعلان و تم — بی جست‌وجو و ماشین‌حساب — در چهار عرض', async ({
  browser,
}, testInfo) => {
  const phone = INITIAL[testInfo.project.name]
  test.skip(phone === undefined, 'حساب‌های نوار فقط برای سه موتورِ دسکتاپ ساخته شده است.')
  const page = await signedIn(browser, phone)
  await page.goto('/dashboard')
  const bar = page.getByRole('banner')
  const t = tools(bar)

  for (const width of WIDTHS) {
    await page.setViewportSize({ width, height: 800 })
    await expect(t.notifications).toBeVisible()
    await expect(t.theme).toBeVisible()
    await expect(bar.getByRole('button', { name: /^حساب کاربری/ })).toBeVisible()
    await expect(t.searchBox).toHaveCount(0)
    await expect(t.searchToggle).toHaveCount(0)
    await expect(t.recent).toHaveCount(0)
    await expect(t.calculator).toHaveCount(0)
    await assertTidy(page, width)
  }
  await page.context().close()
})
