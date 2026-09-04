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
 * ⚠️ از کلاسِ `min-[600px]:hidden` در `HomeNavbar` گرفته شده. اگر آنجا
 * عوض شود و اینجا نه، تستِ منو در عرضِ اشتباه اجرا می‌شود.
 */
const BURGER_BREAKPOINT = 600

/** صفحه‌های عمومی که مهمان می‌بیند؛ بیشترین ترافیک را دارند. */
const PUBLIC_PAGES = ['/', '/demo', '/support', '/auth'] as const

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

      if (box && (box.width < 44 || box.height < 44)) {
        small.push(
          `${(await button.textContent())?.trim().slice(0, 30)} → ${box.width}×${box.height}`,
        )
      }
    }

    expect(small, `دکمه‌های کوچک‌تر از ۴۴ پیکسل:\n  ${small.join('\n  ')}`).toEqual([])
  })
})
