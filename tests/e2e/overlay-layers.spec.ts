import { expect, test, type Browser, type Page } from '@playwright/test'

/**
 * باکسِ اعلان روی همه‌ی بخش‌های داشبورد — «چند نکته»، بندِ ۵.
 *
 * jsdom stacking context را حساب نمی‌کند؛ پس مدرکِ واقعی همین مرورگر است: باکس باز می‌شود و در نقطه‌ای که با نمایشگرِ
 * چسبانِ ماشین‌حساب هم‌پوشانی دارد، `elementFromPoint` باید مالِ خودِ باکس باشد — نه ماشین‌حساب. پیش از اصلاح، سربرگ
 * z-20 بود و نمایشگرِ ماشین‌حساب هم z-20 و بعدتر در DOM، پس روی باکس می‌نشست.
 */

// یک حساب برای هر موتور (`E2eSeeder::LAYER_MEMBERS`): ورودِ مدیرِ نمایشی سقفِ پنج ورود در دقیقه را با سفرهای دیگر
// پر می‌کرد و `registrant-journey` در CI ِ `edd7c9a` ۴۲۹ گرفت
const MEMBERS: Record<string, string> = {
  chromium: '09120000097',
  firefox: '09120000098',
  webkit: '09120000099',
}

async function signedIn(browser: Browser, phone: string): Promise<Page> {
  const page = await (await browser.newContext()).newPage()
  await page.goto('/auth')
  const status = await page.evaluate(
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
    { phone, password: 'password' },
  )
  expect(status).toBe(200)
  return page
}

/** نقطه‌ای در هم‌پوشانیِ باکس و نمایشگر؛ و اینکه بالاترین عنصرِ آن نقطه مالِ باکس است */
async function topmostAtOverlapIsThePanel(page: Page): Promise<boolean> {
  return page.evaluate(() => {
    const panel = document.querySelector('[data-layer="notifications"]')
    const display = document.querySelector('input[aria-label="عبارت ریاضی"]')?.closest('.sticky')
    if (!panel || !display) throw new Error('باکس یا نمایشگرِ ماشین‌حساب پیدا نشد.')

    const a = panel.getBoundingClientRect()
    const b = display.getBoundingClientRect()
    const left = Math.max(a.left, b.left)
    const right = Math.min(a.right, b.right)
    const top = Math.max(a.top, b.top)
    const bottom = Math.min(a.bottom, b.bottom)
    if (right - left < 4 || bottom - top < 4)
      throw new Error('باکس و نمایشگر هم‌پوشانی ندارند؛ آزمون چیزی نمی‌سنجد.')

    const hit = document.elementFromPoint((left + right) / 2, (top + bottom) / 2)
    return hit !== null && panel.contains(hit)
  })
}

/** باکس را با هاور باز می‌کند (زنگوله با هاور باز می‌شود و کلیک بازوبسته‌اش می‌کند) و هم‌پوشانی را می‌سنجد */
async function openAndCheck(page: Page) {
  await page.goto('/calculator')
  const input = page.getByLabel('عبارت ریاضی')
  await expect(input).toBeVisible()

  await page.getByRole('button', { name: /اعلان/ }).first().hover()
  const panel = page.locator('[data-layer="notifications"]')
  await expect(panel).toBeVisible()
  // پایانِ انیمیشنِ بازشدن
  await expect.poll(() => panel.evaluate((el) => getComputedStyle(el).opacity)).toBe('1')

  expect(
    await topmostAtOverlapIsThePanel(page),
    'نمایشگرِ ماشین‌حساب روی باکسِ اعلان نشسته است.',
  ).toBe(true)

  // کلیکِ بیرون باکس — در حاشیه‌ی خالیِ ناحیه‌ی محتوا، بیرون از باکس در هر دو اندازه — باکس را می‌بندد
  const main = await page.locator('main').boundingBox()
  if (!main) throw new Error('ناحیه‌ی محتوا پیدا نشد.')
  await page.mouse.click(main.x + 4, main.y + main.height - 4)
  await expect(panel).toBeHidden()

  // و ماشین‌حساب همچنان کار می‌کند
  await input.fill('6*7')
  await input.press('Enter')
  await expect(page.getByText('= ۴۲').first()).toBeVisible()
}

// یک ورود برای هر دو اندازه: سقفِ نرخِ ورود برای یک شماره (۴۲۹) در اجرای کامل
test('باکسِ اعلان روی ماشین‌حساب است، نه زیرِ آن — دسکتاپ و موبایل', async ({
  browser,
}, testInfo) => {
  test.setTimeout(90_000)
  const phone = MEMBERS[testInfo.project.name]
  test.skip(phone === undefined, 'حسابِ لایه فقط برای سه موتورِ دسکتاپ ساخته شده است.')
  const page = await signedIn(browser, phone)

  await openAndCheck(page)

  await page.setViewportSize({ width: 390, height: 844 })
  await openAndCheck(page)

  await page.context().close()
})
