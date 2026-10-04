import { expect, test, type Page } from '@playwright/test'

/**
 * بلاگ بی‌بارگذاریِ دوباره (مرحلهٔ ۷ · فاز ۷) — سناریوهای بندِ ۳۱ ِ ممیزی.
 *
 * ⚠️ «بی‌بارگذاری» با یک نشانه روی `window` سنجیده می‌شود: بارگذاریِ کاملِ سند
 * آن را پاک می‌کند. بی‌این، تستی که فقط آدرس و متن را می‌سنجید با بارگذاریِ
 * کامل هم سبز می‌شد.
 *
 * داده: ۱۵ مقاله‌ی واقعیِ `resources/content/blog` که `E2eSeeder` وارد می‌کند.
 * ⚠️ `blog-journey` در همان پایگاه‌داده برای هر موتور مقاله‌ی تازه منتشر می‌کند؛
 * پس تعدادِ صفحه‌ی دوم از خودِ API خوانده می‌شود و مقاله‌ی «کامل» با نشانیِ
 * یک مقاله‌ی واردشده باز می‌شود، نه «اولین کارت».
 */

const SEARCH = 'ساکنا' // در همه‌ی مقاله‌ها هست → دو صفحه نتیجه
const IMPORTED_SLUG = 'پاسخ-به-سوالات-تکراری-ساکنین'
const IMPORTED_TITLE = 'پرسش‌های تکراری ساکنین؛ از دستیار پشتیبانی تا فرم بازخورد'

/** تعدادِ کارت‌های صفحه‌ی دوم همان‌طور که سرور الان می‌شمارد */
async function secondPageCount(page: Page, q?: string): Promise<number> {
  const query = q ? `q=${encodeURIComponent(q)}&page=2` : 'page=2'
  const res = await page.request.get(`/api/blog?${query}`, {
    headers: { Accept: 'application/json' },
  })
  expect(res.ok()).toBe(true)
  const body = (await res.json()) as { data: { items: unknown[]; total: number } }
  expect(body.data.total, 'دست‌کم ۱۱ مقاله لازم است تا صفحه‌ی دوم باشد').toBeGreaterThan(10)
  return body.data.items.length
}

async function mark(page: Page) {
  // ⚠️ کلیک پیش از سوارشدنِ ناوبری ناوبریِ معمولی است (پس‌رفتِ درست)، نه باگ
  await expect(page.locator('main[data-blog-enhanced]')).toBeAttached()
  await page.evaluate(
    () => ((window as unknown as { __sameDocument: boolean }).__sameDocument = true),
  )
}

async function sameDocument(page: Page): Promise<boolean> {
  return page.evaluate(
    () => (window as unknown as { __sameDocument?: boolean }).__sameDocument === true,
  )
}

const cards = (page: Page) => page.locator('main article.blog-card')
const heading = (page: Page) => page.locator('main h1')

test.describe('بلاگ: صفحه‌بندی، جست‌وجو و مقاله بی‌بارگذاری', () => {
  test('ده مقاله، صفحه‌ی دوم و بازگشت/جلو', async ({ page }) => {
    const second = await secondPageCount(page)
    await page.goto('/blog')
    await expect(cards(page)).toHaveCount(10)
    await mark(page)

    await page.getByRole('link', { name: 'صفحه‌ی ۲' }).click()
    await expect(page).toHaveURL(/\/blog\?page=2$/)
    await expect(cards(page)).toHaveCount(second)
    expect(await sameDocument(page), 'صفحه‌ی دوم بارگذاریِ کامل بود').toBe(true)
    await expect(page.locator('.blog-pager [aria-current="page"]')).toHaveText('۲')

    await page.goBack()
    await expect(page).toHaveURL(/\/blog$/)
    await expect(cards(page)).toHaveCount(10)
    await page.goForward()
    await expect(cards(page)).toHaveCount(second)
    expect(await sameDocument(page)).toBe(true)
  })

  test('جست‌وجو → صفحه‌ی دو → مقاله → بازگشت: وضعیت برمی‌گردد', async ({ page }) => {
    await page.goto('/blog')
    await mark(page)

    await page.getByRole('searchbox', { name: 'جست‌وجو در بلاگ' }).fill(`  ${SEARCH}  `)
    await page.getByRole('button', { name: 'جست‌وجو' }).click()

    await expect(page).toHaveURL(new RegExp(`\\?q=${encodeURIComponent(SEARCH)}$`))
    await expect(
      page.getByRole('status').filter({ hasText: `مقاله برای «${SEARCH}»` }),
    ).toBeVisible()
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', 'noindex,follow')

    await page.getByRole('link', { name: 'صفحه‌ی ۲' }).click()
    await expect(page).toHaveURL(new RegExp(`q=${encodeURIComponent(SEARCH)}&page=2$`))
    const firstOnPageTwo = await cards(page).first().locator('.blog-card-title').innerText()

    await cards(page).first().locator('.blog-card-title a').click()
    await expect(page).toHaveURL(/\/blog\/[^/?]+$/)
    await expect(heading(page)).toHaveText(firstOnPageTwo)
    await expect(page.locator('meta[name="robots"]')).toHaveCount(0)

    await page.goBack()
    await expect(page).toHaveURL(new RegExp(`q=${encodeURIComponent(SEARCH)}&page=2$`))
    await expect(cards(page).first().locator('.blog-card-title')).toHaveText(firstOnPageTwo)
    await expect(page.getByRole('searchbox', { name: 'جست‌وجو در بلاگ' })).toHaveValue(SEARCH)
    expect(await sameDocument(page), 'جایی بارگذاریِ کامل رخ داد').toBe(true)
  })

  test('مقاله کامل است: تاریخِ شمسی، زمانِ مطالعه، جلد، تصویرِ متن، مرتبط‌ها و CTA', async ({
    page,
  }) => {
    await page.goto(`/blog?q=${encodeURIComponent('پرسش‌های تکراری')}`)
    await mark(page)
    await page.getByRole('link', { name: IMPORTED_TITLE }).click()
    await expect(page).toHaveURL(new RegExp(`/blog/${encodeURIComponent(IMPORTED_SLUG)}$`))

    // مسیرِ راهنما بیرونِ `<main>` است و باید با مقاله عوض شود، نه بماند
    const crumbs = page.getByRole('navigation', { name: 'مسیر راهنما' })
    await expect(crumbs.locator('[aria-current="page"]')).toHaveText(IMPORTED_TITLE)
    await expect(crumbs.getByRole('link', { name: 'بلاگ' })).toBeVisible()

    const article = page.locator('main article.blog-article')
    // مرحلهٔ ۸ · فاز ۱ · بندِ ۲۱: فوترِ صفحه‌ی اصلی این‌جا نیست، حتی پس از سوارشدنِ جزیره‌ها
    await expect(page.locator('footer')).toHaveCount(0)
    await expect(article.locator('time')).toHaveText(/^[۰-۹]{4}\/[۰-۹]{2}\/[۰-۹]{2}$/)
    await expect(article).toContainText(/[۰-۹]+ دقیقه مطالعه/)
    await expect(article.locator('img.blog-cover')).toBeVisible()
    await expect(article.locator('.blog-prose img').first()).toBeAttached()
    await expect(page.locator('.blog-related article.blog-card')).toHaveCount(3)
    await expect(page.getByRole('link', { name: 'مشاهده‌ی دمو' })).toHaveAttribute('href', '/demo')
    expect(await sameDocument(page)).toBe(true)

    // بارگذاریِ دوباره روی آدرسِ مقاله: همان مقاله از سرور
    const title = await heading(page).innerText()
    await page.reload()
    await expect(heading(page)).toHaveText(title)
  })

  test('بارگذاریِ دوباره روی آدرسِ جست‌وجو و صفحه همان وضعیت را از سرور می‌دهد', async ({
    page,
  }) => {
    const second = await secondPageCount(page, SEARCH)
    await page.goto(`/blog?q=${encodeURIComponent(SEARCH)}&page=2`)
    await expect(cards(page)).toHaveCount(second)
    await expect(page.getByRole('searchbox', { name: 'جست‌وجو در بلاگ' })).toHaveValue(SEARCH)
  })

  test('بی‌نتیجه، خالی و یک‌حرفی', async ({ page }) => {
    await page.goto('/blog')
    const requests: string[] = []
    page.on('request', (r) => r.url().includes('/api/blog') && requests.push(r.url()))
    const box = page.getByRole('searchbox', { name: 'جست‌وجو در بلاگ' })

    await box.fill('   ')
    await page.getByRole('button', { name: 'جست‌وجو' }).click()
    await expect(page.locator('[data-blog-search-hint]')).toHaveText('عبارتی برای جست‌وجو بنویسید.')

    await box.fill('ش')
    await page.getByRole('button', { name: 'جست‌وجو' }).click()
    await expect(page.locator('[data-blog-search-hint]')).toContainText('دست‌کم')
    expect(requests, 'جست‌وجوی نامعتبر درخواست فرستاد').toEqual([])

    await box.fill('واژه‌ای‌که‌هیچ‌جا‌نیست')
    await page.getByRole('button', { name: 'جست‌وجو' }).click()
    await expect(page.getByText('مقاله‌ای برای جست‌وجوی شما پیدا نشد.')).toBeVisible()
  })

  test('خطای API: پیام و «تلاش مجدد»', async ({ page }) => {
    const second = await secondPageCount(page)
    await page.goto('/blog')
    await mark(page)
    await page.route('**/api/blog?page=2', (route) => route.fulfill({ status: 500, body: '{}' }))

    await page.getByRole('link', { name: 'صفحه‌ی ۲' }).click()
    await expect(page.getByRole('alert')).toContainText('دریافتِ مقالات با مشکل مواجه شد')

    await page.unroute('**/api/blog?page=2')
    await page.getByRole('button', { name: 'تلاش مجدد' }).click()
    await expect(cards(page)).toHaveCount(second)
    expect(await sameDocument(page)).toBe(true)
  })

  test.describe('موبایل', () => {
    test.use({ viewport: { width: 390, height: 844 } })

    test('فهرست و مقاله افقی سرریز نمی‌شوند', async ({ page }) => {
      await page.goto('/blog')
      const overflow = () =>
        page.evaluate(
          () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
        )
      expect(await overflow()).toBeLessThanOrEqual(0)

      await cards(page).nth(1).locator('.blog-card-title a').click()
      await expect(page.locator('main article.blog-article')).toBeVisible()
      expect(await overflow()).toBeLessThanOrEqual(0)
    })
  })
})
