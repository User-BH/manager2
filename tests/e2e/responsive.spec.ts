import { expect, test } from '@playwright/test'

/**
 * رفتارِ صفحه در اندازه‌های واقعی (R70).
 *
 * ─── ⚠️ چرا این تست‌ها فقط اینجا معنی دارند ─────────────────────────────────
 * jsdom طرح‌بندی ندارد: عرض، سرریز و موقعیتِ عناصر همه صفرند. یعنی هیچ‌کدام
 * از ادعاهای این فایل در Vitest قابلِ سنجش نیست — و دقیقاً به همین دلیل
 * تا امروز سنجیده نشده بودند.
 *
 * ─── چه چیزی سنجیده می‌شود و چرا همین‌ها ────────────────────────────────────
 * سه چیزی که در طرحِ راست‌به‌چپ **بیشترین** خرابی را می‌سازند و هیچ‌کدام
 * خطا نمی‌دهند:
 *   ① سرریزِ افقی — کاربر باید صفحه را کنار بکشد تا متن را ببیند
 *   ② ناوبریِ موبایل — منو باز نشود یعنی سایت در گوشی بی‌استفاده است
 *   ③ اندازه‌ی هدفِ لمس — دکمه‌ی کوچک روی انگشت کار نمی‌کند
 */

/**
 * عرضی که از آن به بعد ناوبریِ کامل جای منوی برگر را می‌گیرد.
 *
 * ⚠️ از کلاسِ `md:hidden` در `HomeNavbar` گرفته شده. اگر آنجا عوض شود و
 * اینجا نه، تستِ منو در عرضِ اشتباه اجرا می‌شود.
 *
 * 🔴 تا مرحلهٔ ۷ · فاز ۵ این عدد ۶۰۰ بود — و همین باگ بود: برگر زیرِ ‎۶۰۰
 * پنهان می‌شد ولی پیوندها تازه از ‎۷۶۸ می‌آمدند.
 */
const BURGER_BREAKPOINT = 768

/**
 * کمینه‌ی اندازه‌ی هدفِ لمس، با ارفاقِ زیرپیکسلی.
 *
 * ⚠️ عددِ راهنمای اپل و گوگل ۴۴ است، ولی مقایسه‌ی دقیق با عددِ اعشاریِ
 * چیدمان شکننده است: همان دکمه‌ای که در ویندوز ۴۴ گزارش می‌شود، روی
 * رانرِ لینوکسیِ CI **۴۳٫۹۹۹۹۹۸** می‌شود.
 *
 * 🔴 و این را CI لو داد، نه اجرای محلی — همان درسِ R70 در شکلی تازه:
 * تستی که فقط روی یک سکو اجرا شود، به دقتِ همان سکو گره می‌خورد.
 *
 * نیم پیکسل ارفاق، گِردکردنِ موتور را می‌پوشاند و دکمه‌ی واقعاً کوچک
 * (۴۰ پیکسل و کمتر) همچنان رد می‌شود.
 */
const MIN_TOUCH_TARGET = 43.5

/** صفحه‌های عمومی که مهمان می‌بیند؛ بیشترین ترافیک را دارند. */
const PUBLIC_PAGES = ['/', '/demo', '/support', '/auth', '/blog'] as const

/**
 * 🔴 باگِ ‎۶۰۰–۷۶۸ (مرحلهٔ ۷ · فاز ۵) — در عرضِ ثابت، مستقل از نمایه‌ی پروژه.
 *
 * در این فاصله شش مقصدِ هدر (سه بخشِ صفحه و دمو، بلاگ، سوالات متداول) در
 * هیچ‌جای هدر نبودند، در هر چهار صفحه‌ی عمومی. هیچ نمایه‌ی Playwright در این
 * بازه نیست (موبایل ‎۳۹۰، تبلت ‎۷۶۸)، پس عرض اینجا صریح گذاشته می‌شود.
 */
test.describe('هدر در ‎۶۰۰ تا ‎۷۶۸', () => {
  test.use({ viewport: { width: 700, height: 800 } })

  for (const path of ['/', '/demo', '/support'] as const) {
    test(`${path}: منو هست و مقصدها در آن دیده می‌شوند`, async ({ page }) => {
      await page.goto(path)
      await page.waitForLoadState('networkidle')

      const menu = page.getByRole('button', { name: 'منو' })
      await expect(menu).toBeVisible()
      await menu.click()

      // پیوندهای دسکتاپ در همان هدر هستند ولی پنهان؛ فقط دیده‌شده‌ها
      const header = page.getByRole('banner')
      const expected =
        path === '/'
          ? ['ویژگی‌ها', 'تور پنل', 'نظرات', 'دمو']
          : path === '/demo'
            ? ['سوالات متداول']
            : ['دمو']
      for (const name of expected) {
        await expect(
          header.getByRole('button', { name, exact: true }).locator('visible=true'),
        ).toBeInViewport()
      }
    })
  }

  /*
   * «تور پنل» و «نظرات» تنبل‌اند. پیش از این شناسه روی خودِ بخش بود و پیوند
   * تا بارگذاریِ بخش هیچ کاری نمی‌کرد؛ جای‌گیرها هم ‎۲۴ تا ‎۱۸۰ پیکسل با محتوا
   * فرق داشتند و حرکت از عنوان دور می‌ایستاد.
   */
  test('پیوندِ «تور پنل» پیش از بارگذاریِ بخش، دقیقاً روی عنوانش می‌ایستد', async ({ page }) => {
    await page.goto('/')
    await page.waitForLoadState('networkidle')

    await page.getByRole('button', { name: 'منو' }).click()
    await page
      .getByRole('banner')
      .getByRole('button', { name: 'تور پنل', exact: true })
      .locator('visible=true')
      .click()

    const heading = page.getByRole('heading', { name: 'تور پنل' })
    await expect(heading).toBeInViewport({ timeout: 10_000 })
    await expect
      .poll(async () => Math.round((await page.locator('#panel-tour').boundingBox())?.y ?? -1), {
        timeout: 5_000,
      })
      .toBeLessThanOrEqual(78)

    /*
     * ⚠️ جای **نهایی**، پس از آرام‌شدنِ صفحه — نه لحظه‌ی رسیدن.
     *
     * 🔴 خرابکاری این را خواست: با جای‌گیرِ ثابتِ قدیمِ تبلیغ (‎۲۲۴ به‌جای
     * ‎۲۴۸) حرکت درست روی ‎۷۲ می‌رسید و تست سبز می‌شد، ولی تبلیغ کمی بعد
     * بار می‌شد و عنوان در WebKit به ‎۹۶ می‌رفت — همان چیزی که کاربر می‌بیند.
     */
    await page.waitForLoadState('networkidle')
    await page.waitForTimeout(1_000)
    const top = Math.round((await page.locator('#panel-tour').boundingBox())!.y)
    // ارتفاعِ هدر ‎۷۲ است؛ کمتر یعنی عنوان زیرِ هدر رفته، بیشتر یعنی دور ایستاده
    expect(top).toBeGreaterThanOrEqual(66)
    expect(top).toBeLessThanOrEqual(78)
  })
})

/**
 * 🔴 جای‌گیرِ هر بخشِ تنبل هم‌قدِ محتوای واقعی‌اش (مرحلهٔ ۷ · فاز ۵).
 *
 * سه جای‌گیرِ صفحه‌ی اصلی ‎۲۴ تا ‎۱۸۰ پیکسل با محتوا فرق داشتند: هنگامِ
 * رسیدنِ محتوا صفحه می‌پرید، و اگر تبلیغ **پس از** پایانِ حرکت می‌رسید، پرش
 * از هدر به «تور پنل» از عنوان دور می‌ایستاد (WebKit ‎۹۶ به‌جای ‎۷۲).
 *
 * ⚠️ این همان را مستقیم و بی‌وابستگی به زمان‌بندی می‌سنجد؛ تستِ «پرش» به
 * زمانِ رسیدنِ تبلیغ وابسته است و خرابکاری نشان داد همیشه نمی‌گیردش.
 * ارفاقِ ‎۸px برای شکستنِ متفاوتِ خط در موتورها؛ خطای واقعی ‎۲۴+ است.
 */
test('جای‌گیرِ بخش‌های تنبلِ صفحه‌ی اصلی هم‌قدِ محتوای واقعی است', async ({ page }) => {
  /*
   * ⚠️ چانکِ سه بخش نگه داشته می‌شود تا «پیش» واقعاً جای‌گیر باشد. روی
   * موبایل تبلیغ نزدیکِ بالای صفحه است و همان لحظه بار می‌شد؛ اندازه‌ی «پیش»
   * آن‌وقت نیمه‌ی راهِ آماده‌شدنِ اسلایدر بود، نه جای‌گیر.
   */
  const held = /\/(AdBannerSection|GallerySwiperSection|TestimonialsSection)-[\w-]+\.js$/
  await page.route(held, () => {})
  await page.goto('/')
  await page.waitForLoadState('domcontentloaded')
  await expect(page.locator('main > div')).toHaveCount(3)

  const heights = () =>
    page.evaluate(() =>
      [...document.querySelectorAll('main > div')].map((d) =>
        Math.round(d.getBoundingClientRect().height),
      ),
    )
  const before = await heights()

  await page.unrouteAll({ behavior: 'ignoreErrors' })
  await page.reload()
  await page.waitForLoadState('networkidle')
  for (let y = 0; y < 9000; y += 400) {
    await page.evaluate((v) => window.scrollTo(0, v), y)
    await page.waitForTimeout(100)
  }
  await page.waitForLoadState('networkidle')
  await page.waitForTimeout(800)
  const after = await heights()

  expect(after.length).toBe(3)
  after.forEach((real, i) => {
    expect(
      Math.abs(real - before[i]),
      `بخشِ ${i + 1}: جای‌گیر ${before[i]} ≠ واقعی ${real}`,
    ).toBeLessThanOrEqual(8)
  })
})

/**
 * ردیفِ آخرِ فوتر (مرحلهٔ ۸ · فاز ۱): کوتاه، ولی زیرِ دکمه‌های ثابت نه.
 *
 * ‎۹۶px ِ خالیِ فاز ۵ برای دورماندن از دکمه‌ی دستیار و «بالا» بود؛ حالا
 * خودِ ردیف از ستونِ آن دکمه‌ها کنار می‌رود. هر دو با هم سنجیده می‌شوند:
 * فقط «فاصله کم شد» روی گوشی متن را زیرِ دکمه می‌برد، فقط «هم‌پوشانی ندارد»
 * با همان فاصله‌ی بلندِ قبلی هم سبز بود. مستطیلِ **متن** (Range) سنجیده
 * می‌شود، نه بلوکِ `p` که تمامِ عرض را می‌گیرد.
 */
test('ردیفِ آخرِ فوتر کوتاه است و هیچ‌جا زیرِ دکمه‌های ثابت نمی‌رود', async ({ page }) => {
  await page.goto('/')
  await page.waitForLoadState('networkidle')

  for (const width of [360, 390, 600, 640, 768, 1024, 1279, 1280, 1440]) {
    await page.setViewportSize({ width, height: 800 })
    for (let i = 0; i < 3; i++) {
      await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight))
      await page.waitForTimeout(250)
    }

    const report = await page.evaluate(() => {
      const row = document.querySelector('[data-footer-bottom]')!
      const footer = row.closest('footer')!
      const lines = [...row.querySelectorAll('p')].flatMap((p) => {
        const range = document.createRange()
        range.selectNodeContents(p)
        return [...range.getClientRects()]
      })
      const buttons = [...document.querySelectorAll<HTMLElement>('button, a')]
        .filter((el) => getComputedStyle(el).position === 'fixed' && el.getClientRects().length > 0)
        .map((el) => ({
          name: el.getAttribute('aria-label') ?? '',
          rect: el.getBoundingClientRect(),
        }))
      const hits = lines.flatMap((line) =>
        buttons
          .filter(
            ({ rect }) =>
              line.right > rect.left &&
              line.left < rect.right &&
              line.bottom > rect.top &&
              line.top < rect.bottom,
          )
          .map(({ name }) => name),
      )
      const gap = footer.getBoundingClientRect().bottom - Math.max(...lines.map((l) => l.bottom))
      return { hits, gap: Math.round(gap), buttons: buttons.length }
    })

    expect(report.buttons, `${width}px: دکمه‌ی ثابتی دیده نشد`).toBeGreaterThan(0)
    expect(report.hits, `${width}px: متنِ فوتر زیرِ ${report.hits.join('، ')}`).toEqual([])
    expect(report.gap, `${width}px: فضای خالیِ زیرِ ردیفِ آخر`).toBeLessThanOrEqual(40)
  }
})

/**
 * توضیحِ کارت‌های ویژگی دوطرفه‌چین است (مرحلهٔ ۸ · فاز ۱ · بندِ ۳) — مگر
 * کارت آن‌قدر باریک باشد که فاصله‌ی کلمه‌ها کش بیاید (‎۶۴۰–۷۶۸، دو ستون).
 * مرزها از شبکه‌ی کارت‌ها حساب شده‌اند (app.css)؛ هر دو سوی مرزِ ‎۷۱۲ سنجیده می‌شود.
 */
test('توضیحِ ویژگی‌ها دوطرفه‌چین است، جز در کارتِ باریک', async ({ page }) => {
  await page.goto('/')
  for (const [width, expected] of [
    [375, 'justify'],
    [640, 'start'],
    [700, 'start'],
    [712, 'justify'],
    [768, 'justify'],
    [1280, 'justify'],
  ] as const) {
    await page.setViewportSize({ width, height: 900 })
    await page.locator('#features').scrollIntoViewIfNeeded()
    const text = page.locator('.features-text').first()
    await expect(text).toBeAttached()
    const { align, cardWidth } = await text.evaluate((p) => ({
      align: getComputedStyle(p).textAlign,
      cardWidth: Math.round(p.getBoundingClientRect().width),
    }))
    expect(align, `${width}px (متن ${cardWidth}px)`).toBe(expected)
  }
})

test.describe('اندازه‌ها', () => {
  /**
   * 🔴 مهم‌ترین ادعای این فایل: هیچ صفحه‌ای نباید افقی اسکرول شود.
   *
   * ⚠️ یک عنصر با `width` ثابت یا `padding` فراموش‌شده کافی است تا در
   * گوشی، متن از لبه بیرون بزند. این خطا نمی‌دهد، در لاگ نمی‌آید، و در
   * دسکتاپ اصلاً دیده نمی‌شود.
   *
   * ارفاقِ یک پیکسل عمدی است: گِردکردنِ زیرپیکسلیِ مرورگر می‌تواند
   * `scrollWidth` را یک واحد بزرگ‌تر کند بی‌آنکه چیزی واقعاً سرریز باشد.
   */
  for (const path of PUBLIC_PAGES) {
    test(`${path} افقی سرریز نمی‌شود`, async ({ page }) => {
      await page.goto(path)
      await page.waitForLoadState('networkidle')

      const overflow = await page.evaluate(() => {
        const element = document.documentElement

        return {
          scroll: element.scrollWidth,
          client: element.clientWidth,
          // ⚠️ نامِ عنصرِ مقصر هم لازم است، وگرنه پیامِ شکست فقط دو عدد است
          culprits: [...document.querySelectorAll<HTMLElement>('body *')]
            .filter((node) => node.getBoundingClientRect().right > element.clientWidth + 1)
            .slice(0, 5)
            .map((node) => `${node.tagName.toLowerCase()}.${node.className}`.slice(0, 90)),
        }
      })

      expect(
        overflow.scroll,
        `سرریزِ افقی در ${path}: ${overflow.scroll} > ${overflow.client}\n` +
          `مقصرهای محتمل:\n  ${overflow.culprits.join('\n  ')}`,
      ).toBeLessThanOrEqual(overflow.client + 1)
    })
  }

  /**
   * ⚠️ محتوای اصلی باید بدونِ تعامل دیده شود.
   *
   * صفحه‌ای که سرریز ندارد ولی عنوانش زیرِ هدرِ چسبیده پنهان شده، همان‌قدر
   * خراب است.
   */
  test('عنوانِ صفحه‌ی اصلی در همان نگاهِ اول دیده می‌شود', async ({ page }) => {
    await page.goto('/')

    const heading = page.locator('h1').first()

    await expect(heading).toBeVisible()

    const box = await heading.boundingBox()
    const viewport = page.viewportSize()

    expect(box, 'عنوان جعبه‌ای ندارد').not.toBeNull()
    expect(box!.y, 'عنوان بیرون از نمای اول است').toBeLessThan(viewport!.height)
  })

  /**
   * 🔴 ناوبری باید در هر اندازه‌ای راهی داشته باشد.
   *
   * ⚠️ عمداً به کلاسِ CSS یا عرضِ خاص گره نخورده: در موبایل دکمه‌ی منو و
   * در دسکتاپ لینک‌ها. تست فقط می‌پرسد «راهی برای رفتن به ورود هست؟» —
   * وگرنه با هر تغییرِ نقطه‌ی شکست، تست می‌شکست بی‌آنکه چیزی خراب شده
   * باشد.
   */
  test('راهی برای رسیدن به صفحه‌ی ورود وجود دارد', async ({ page }) => {
    await page.goto('/')
    await page.waitForLoadState('networkidle')

    /*
     * ⚠️ نقش را قید نمی‌کنیم، و این عمدی است.
     *
     * 🔴 نسخه‌ی اول فقط دنبالِ `link` بود و در نمای تبلت شکست — چون همان
     * «ورود» آنجا `button` است (ناوبری با کد انجام می‌شود، نه با `href`).
     *
     * کاربر دنبالِ **کلمه** می‌گردد نه دنبالِ تگ. تستی که به تگ گره بخورد،
     * با هر بازآراییِ بی‌ضررِ رابط می‌شکند و چیزی هم اثبات نمی‌کند.
     */
    const entry = page
      .getByRole('link', { name: /ورود/ })
      .or(page.getByRole('button', { name: /ورود/ }))

    if (
      await entry
        .first()
        .isVisible()
        .catch(() => false)
    ) {
      await entry.first().click()
    } else {
      // نمای فشرده: اول باید منو باز شود
      await page.getByRole('button', { name: 'منو' }).click()
      await entry.first().click()
    }

    await page.waitForURL(/\/auth/, { timeout: 15_000 })
  })

  /**
   * 🔴 منوی فشرده باید واقعاً باز شود.
   *
   * ─── چرا این تستِ جداست و شاخه‌ی جایگزینِ تستِ بالا نیست ─────────────────
   * ⚠️ نسخه‌ی اولِ تستِ بالا منو را فقط به‌عنوان **راهِ دوم** امتحان
   * می‌کرد: اگر «ورود» مستقیم دیده می‌شد، سراغِ منو نمی‌رفت.
   *
   * خرابکاری نشان داد آن شاخه **هرگز اجرا نمی‌شود** — در نمای موبایل هم
   * لینکِ «ورود» دیده می‌شود، پس منو تست نمی‌شد. برداشتنِ برچسبِ دسترسیِ
   * دکمه‌ی منو از تور رد شد.
   *
   * پس ادعای «منو کار می‌کند» باید ادعای مستقلِ خودش باشد، نه اثرِ جانبیِ
   * ادعای دیگری.
   */
  test('منوی فشرده باز می‌شود و ناوبری را نشان می‌دهد', async ({ page }) => {
    /*
     * ⚠️ شرط بر اساسِ **عرض** است، نه `isMobile`.
     *
     * 🔴 `isMobile` یعنی «لمسی»، نه «باریک». تبلت هر دو را ندارد: لمسی
     * هست ولی عرضش به‌قدری هست که ناوبریِ کامل نشان داده شود — و تست
     * دنبالِ دکمه‌ای می‌گشت که اصلاً وجود نداشت.
     *
     * عدد از خودِ CSS می‌آید (`min-[600px]:hidden` روی برگر)، پس اگر
     * روزی نقطه‌ی شکست عوض شود، همین‌جا هم باید عوض شود — و آن تغییر
     * دیده می‌شود.
     */
    const width = page.viewportSize()?.width ?? 0

    test.skip(width >= BURGER_BREAKPOINT, 'در این عرض ناوبریِ کامل نشان داده می‌شود')

    await page.goto('/')
    await page.waitForLoadState('networkidle')

    const menu = page.getByRole('button', { name: 'منو' })

    await expect(menu, 'دکمه‌ی منو با برچسبِ دسترسی پیدا نشد').toBeVisible()
    await menu.click()

    /*
     * ⚠️ «باز شد» یعنی محتوایی که پیش‌تر نبود حالا دیده می‌شود — نه اینکه
     * کلیک خطا نداد. بدونِ این ادعا، منویی که باز نمی‌شود هم سبز می‌ماند.
     */
    await expect(
      page
        .getByRole('button', { name: 'ویژگی‌ها' })
        .or(page.getByRole('link', { name: 'ویژگی‌ها' }))
        .first(),
      'پس از باز شدنِ منو، هیچ گزینه‌ی ناوبری‌ای دیده نشد',
    ).toBeVisible({ timeout: 5_000 })
  })

  /**
   * ⚠️ اندازه‌ی هدفِ لمس فقط در نمای لمسی معنی دارد.
   *
   * ۴۴ پیکسل عددِ راهنمای اپل و گوگل است؛ کمتر از آن یعنی کاربر باید
   * چند بار امتحان کند. روی دسکتاپ (ماوس) این قاعده اعمال نمی‌شود و
   * تحمیلش فقط طراحی را بی‌دلیل درشت می‌کند.
   */
  test('دکمه‌های اصلی در نمای لمسی به‌اندازه‌ی انگشت‌اند', async ({ page, isMobile }) => {
    test.skip(!isMobile, 'فقط در نمای لمسی معنی دارد')

    await page.goto('/')

    const small: string[] = []
    const buttons = page.getByRole('button')

    for (let index = 0; index < (await buttons.count()); index++) {
      const button = buttons.nth(index)

      if (!(await button.isVisible())) continue

      const box = await button.boundingBox()

      if (box && (box.width < MIN_TOUCH_TARGET || box.height < MIN_TOUCH_TARGET)) {
        small.push(
          `${(await button.textContent())?.trim().slice(0, 30)} → ${box.width}×${box.height}`,
        )
      }
    }

    expect(
      small,
      `دکمه‌های کوچک‌تر از ${MIN_TOUCH_TARGET} پیکسل:\n  ${small.join('\n  ')}`,
    ).toEqual([])
  })
})

/*
 * موبایلِ واقعی، نه فقط «واکنش‌گرا» (مرحلهٔ ۵ · فاز ۸ · بندِ ۶۷).
 *
 * این دو رفتار را فقط مرورگرِ واقعی با نمای لمسی نشان می‌دهد؛ jsdom نه
 * `pointer: coarse` می‌فهمد نه `overscroll-behavior` را حساب می‌کند.
 */
test.describe('موبایلِ واقعی', () => {
  /**
   * 🔴 Safariِ iOS با فوکوس روی فیلدِ زیرِ ۱۶ پیکسل کلِ صفحه را بزرگ می‌کند
   * و برنمی‌گرداند. همه‌ی فیلدها ۱۳٫۵ پیکسل بودند.
   */
  test('فیلدهای ورود در نمای لمسی دستِ‌کم ۱۶ پیکسل‌اند و در دسکتاپ دست نخورده‌اند', async ({
    page,
    isMobile,
  }) => {
    await page.goto('/auth')
    // ⚠️ فرم را React می‌سازد؛ در WebKit دیرتر از رویدادِ load. بدونِ صبر، شمارش صفر بود
    await expect(
      page.locator('input[type=password]').filter({ visible: true }).first(),
    ).toBeVisible()

    const sizes = await page.evaluate(() =>
      Array.from(document.querySelectorAll('input:not([type=checkbox]):not([type=hidden])'))
        .filter((element) => (element as HTMLElement).offsetParent !== null)
        .map((element) => parseFloat(getComputedStyle(element).fontSize)),
    )

    // ضدِ پوچی: صفحه‌ی ورود دستِ‌کم دو فیلدِ دیدنی دارد
    expect(sizes.length).toBeGreaterThanOrEqual(2)

    if (isMobile) {
      expect(
        Math.min(...sizes),
        'فیلدی زیرِ ۱۶ پیکسل؛ iOS صفحه را بزرگ می‌کند.',
      ).toBeGreaterThanOrEqual(16)
    } else {
      expect(Math.max(...sizes), 'قاعده‌ی لمسی به دسکتاپ نشت کرده است.').toBeLessThan(16)
    }
  })

  /**
   * 🔴 کشیدن به پایین در بالای فهرستِ درونی به صفحه می‌رسید و در Chromeِ
   * اندروید کلِ صفحه را دوباره بار می‌کرد. خودِ `<main>` عمداً مستثناست.
   */
  test('ناحیه‌ی اسکرولِ درونی زنجیره نمی‌سازد، ولی صفحه‌ی اصلی تازه‌سازی را نگه می‌دارد', async ({
    page,
  }) => {
    await page.goto('/auth')
    // ⚠️ فرم را React می‌سازد؛ در WebKit دیرتر از رویدادِ load. بدونِ صبر، شمارش صفر بود
    await expect(
      page.locator('input[type=password]').filter({ visible: true }).first(),
    ).toBeVisible()

    /*
     * ⚠️ WebKitِ Playwright روی ویندوز این ویژگی را ندارد (Safariِ واقعی از
     * نسخه‌ی ۱۶ دارد). آن‌جا صریحاً رد می‌شود و Safariِ واقعی در فهرستِ
     * «نیازمندِ دستگاه» می‌ماند — نه سبزِ جعلی، نه قرمزِ بی‌ربط.
     */
    const supported = await page.evaluate(() => CSS.supports('overscroll-behavior', 'contain'))
    test.skip(!supported, 'این نسخه‌ی موتور overscroll-behavior ندارد')

    const behaviour = await page.evaluate(() => {
      const inner = document.querySelector('.overflow-y-auto:not(main)')
      const main = document.createElement('main')
      main.className = 'overflow-y-auto'
      document.body.appendChild(main)
      const result = {
        inner: inner ? getComputedStyle(inner).overscrollBehaviorY : null,
        main: getComputedStyle(main).overscrollBehaviorY,
      }
      main.remove()
      return result
    })

    expect(behaviour.inner, 'ناحیه‌ی اسکرولِ درونی در صفحه‌ی ورود پیدا نشد.').toBe('contain')
    expect(behaviour.main).toBe('auto')
  })
})
