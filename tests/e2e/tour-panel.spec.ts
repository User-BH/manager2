import { expect, test, type Page } from '@playwright/test'

/**
 * تور پنل در مرورگرِ واقعی (مرحلهٔ ۹ · فاز ۱).
 *
 * حالتِ عادی و حالتِ توضیح بی‌زوم و با لبه‌های کامل؛ زوم فقط با هاور (همان
 * مکانیزمِ قبلی)؛ و عنوانِ زیرِ تصویر حتی روی بخشِ سفیدِ تصویر خوانا.
 * همه با چیدمانِ واقعیِ هر نمایه (گوشی، تبلت، لپ‌تاپ) — jsdom چیدمان ندارد.
 */

const RATIO = 800 / 1000

declare global {
  interface Window {
    __zoomOf: (el: Element) => number
  }
}

async function openTour(page: Page, theme: 'light' | 'dark' = 'light') {
  /*
   * نوارِ گالری بی‌وقفه حرکت می‌کند و Playwright روی عنصرِ متحرک «پایدار»
   * نمی‌شود. «کاهشِ حرکت» تنظیمِ واقعیِ کاربر است و CSS در آن نوار را
   * می‌ایستاند؛ اندازه‌ی قاب، `object-fit`، هاور و ذره‌بین به حرکتِ نوار ربطی
   * ندارند. (`test.use({ reducedMotion })` در این پیکربندی اثر نکرد؛ سنجیده شد.)
   */
  await page.emulateMedia({ reducedMotion: 'reduce' })
  /*
   * ⚠️ تمِ تیره‌ی سایت از `prefers-color-scheme` ِ سیستم‌عامل نمی‌آید (عمداً؛
   * `critical-flows`)؛ کلاسِ `dark` است که انتخابِ کاربر در `sakena:theme`
   * می‌گذارد. نسخه‌ی اولِ این تست حالتِ تیره‌ی سیستم‌عامل را می‌سنجید — در
   * Firefox هم پایدار نبود — و اصلاً تمِ تیره‌ی سایت را روشن نمی‌کرد.
   */
  await page.addInitScript((value) => localStorage.setItem('sakena:theme', value), theme)
  /*
   * بزرگ‌نماییِ واقعیِ یک عنصر. ⚠️ Tailwind 4 `scale-110` را با ویژگیِ مستقلِ
   * `scale` می‌سازد، نه `transform` — نسخه‌ی اولِ همین تست فقط `transform`
   * را می‌سنجید و روی هاورِ واقعی هم «۱» می‌گفت.
   */
  await page.addInitScript(() => {
    window.__zoomOf = (el: Element) => {
      const style = getComputedStyle(el)
      const scale =
        style.scale === 'none' ? 1 : parseFloat(style.scale) / (style.scale.includes('%') ? 100 : 1)
      const m = style.transform.match(/matrix\(([^,]+)/)
      return scale * (m ? Number(m[1]) : 1)
    }
  })
  await page.goto('/')
  await page.locator('#panel-tour').scrollIntoViewIfNeeded()
  const tile = page.locator('.gallery-marquee-item').first()
  await expect(tile).toBeVisible({ timeout: 15_000 })
  await tile.scrollIntoViewIfNeeded()
  return tile
}

const canHover = (page: Page) => page.evaluate(() => matchMedia('(hover: hover)').matches)

test('حالتِ عادی: قاب هم‌نسبتِ تصویر، `contain`، بی هیچ بزرگ‌نمایی', async ({ page }) => {
  const tile = await openTour(page)
  // نشانگر بیرون از گالری؛ وگرنه هاورِ مجاز را با «زوم در حالتِ عادی» اشتباه می‌گیریم
  await page.mouse.move(0, 0)

  /*
   * ⚠️ تصویر `loading="lazy"` است: پیش از بار شدن `naturalWidth` صفر است و
   * نسبت NaN می‌شد. در CI ِ `0f45f77` همین روی Chromium و Firefox یک بار شکست
   * و با تلاشِ دوباره سبز شد — «ناپایدار» ِ پنهان. حالا تا بار شدنِ واقعیِ
   * تصویر صبر می‌شود، نه به تلاشِ دوباره.
   */
  await expect
    .poll(() => tile.evaluate((el) => el.querySelector('img')!.naturalWidth), { timeout: 10_000 })
    .toBeGreaterThan(0)

  const state = await tile.evaluate((el) => {
    const img = el.querySelector('img')!
    const r = el.getBoundingClientRect()
    return {
      ratio: r.width / r.height,
      natural: img.naturalWidth / img.naturalHeight,
      fit: getComputedStyle(img).objectFit,
      zoom: window.__zoomOf(img),
    }
  })

  expect(state.ratio).toBeCloseTo(RATIO, 2)
  expect(state.natural).toBeCloseTo(RATIO, 2)
  expect(state.fit).toBe('contain')
  expect(state.zoom).toBe(1)
})

test('حالتِ توضیح: کلِ تصویر دیده می‌شود و تا نشانگر نیاید ذره‌بین نیست', async ({ page }) => {
  const tile = await openTour(page)
  // با کیبورد باز می‌شود تا هیچ هاوری در کار نباشد
  await tile.focus()
  await page.keyboard.press('Enter')

  const dialog = page.getByRole('dialog')
  await expect(dialog).toBeVisible()
  const img = dialog.locator('img').first()
  await expect(img).toBeVisible()

  const state = await img.evaluate((el) => {
    const frame = el.parentElement!.getBoundingClientRect()
    const box = el.getBoundingClientRect()
    return {
      fit: getComputedStyle(el).objectFit,
      zoom: window.__zoomOf(el),
      opacity: getComputedStyle(el).opacity,
      lens: [...el.parentElement!.querySelectorAll<HTMLElement>('div')].some(
        (d) => d.style.backgroundSize,
      ),
      // جعبه‌ی img همان قاب است؛ با `contain` کلِ تصویر داخلِ آن می‌نشیند
      fills: Math.abs(box.width - frame.width) < 1 && Math.abs(box.height - frame.height) < 1,
    }
  })

  expect(state).toEqual({ fit: 'contain', zoom: 1, opacity: '1', lens: false, fills: true })
})

test('زوم فقط با هاور: قابِ گالری و ذره‌بینِ حالتِ توضیح همان قبلی‌اند', async ({ page }) => {
  const tile = await openTour(page)
  test.skip(!(await canHover(page)), 'دستگاهِ لمسی هاور ندارد')

  await tile.hover()
  await expect
    .poll(() => tile.evaluate((el) => window.__zoomOf(el.querySelector('img')!)))
    .toBeCloseTo(1.1, 2)

  await tile.click()
  const frame = page.getByRole('dialog').locator('img').first().locator('xpath=..')
  await frame.hover()
  await expect
    .poll(() =>
      frame.evaluate(
        (el) =>
          [...el.querySelectorAll<HTMLElement>('div')].find((d) => d.style.backgroundSize)?.style
            .backgroundSize ?? '',
      ),
    )
    .toMatch(/^240%/)
})

/**
 * کنتراستِ عنوان و سرفصل روی بدترین زمینه — تصویرِ سفیدِ کامل — از روی
 * گرادیانِ واقعیِ محاسبه‌شده و جای واقعیِ هر خط در همین نمایه.
 */
async function captionContrast(page: Page, theme: 'light' | 'dark' = 'light') {
  await openTour(page, theme)
  return page.evaluate(() => {
    const lin = (c: number) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4)
    const ratio = (alpha: number, white: number) => {
      const bg = 1 - alpha
      const text = white + (1 - white) * bg
      const [hi, lo] = [lin(text), lin(bg)].sort((a, b) => b - a)
      return (hi + 0.05) / (lo + 0.05)
    }

    const tiles = [...document.querySelectorAll('.gallery-marquee-item')].slice(0, 8)
    return tiles.flatMap((tile) => {
      const caption = tile.querySelector<HTMLElement>('[data-tour-caption]')!
      const box = caption.getBoundingClientRect()
      const stops = [
        ...getComputedStyle(caption).backgroundImage.matchAll(/rgba\(([^)]+)\)\s+([\d.]+)(px|%)/g),
      ].map((m) => ({
        alpha: Number(m[1].split(',')[3]),
        y: m[3] === 'px' ? Number(m[2]) : (Number(m[2]) / 100) * box.height,
      }))
      const alphaAt = (y: number) => {
        for (let i = 1; i < stops.length; i++) {
          if (y <= stops[i].y) {
            const [a, b] = [stops[i - 1], stops[i]]
            return a.alpha + ((b.alpha - a.alpha) * (y - a.y)) / Math.max(1, b.y - a.y)
          }
        }
        return stops[stops.length - 1].alpha
      }

      return [...caption.querySelectorAll('span')].map((line, i) => {
        const r = line.getBoundingClientRect()
        // گرادیان رو به پایین تیره‌تر می‌شود؛ بالای خط بدترین نقطه است
        const alpha = alphaAt(r.top - box.top)
        return {
          text: line.textContent,
          contrast: Math.round(ratio(alpha, i === 0 ? 1 : 0.85) * 100) / 100,
          maxAlpha: Math.max(...stops.map((s) => s.alpha)),
        }
      })
    })
  })
}

test('عنوانِ زیرِ تصویر روی سفیدِ کامل هم دست‌کم ۴٫۵:۱ است و نوار سنگین نیست', async ({ page }) => {
  const lines = await captionContrast(page)
  expect(lines.length).toBeGreaterThanOrEqual(8)
  for (const line of lines) {
    expect(line.contrast, `«${line.text}»`).toBeGreaterThanOrEqual(4.5)
    expect(line.maxAlpha).toBeLessThanOrEqual(0.85)
  }
})

test.describe('حالتِ تیره', () => {
  test('همان کنتراست و همان حالتِ بی‌زوم', async ({ page }) => {
    const lines = await captionContrast(page, 'dark')
    expect(await page.evaluate(() => document.documentElement.classList.contains('dark'))).toBe(
      true,
    )
    for (const line of lines) {
      expect(line.contrast, `«${line.text}»`).toBeGreaterThanOrEqual(4.5)
    }
    await page.mouse.move(0, 0)
    const fit = await page
      .locator('.gallery-marquee-item img')
      .first()
      .evaluate((el) => [getComputedStyle(el).objectFit, getComputedStyle(el).transform])
    expect(fit).toEqual(['contain', 'none'])
  })
})

/*
 * نمایه‌های گوشی و تبلت فقط `responsive.spec` را اجرا می‌کنند؛ پس اندازه‌ها
 * اینجا جاروب می‌شوند. ۳۷۵ همان جایی است که لایت‌باکس با `cover` حدودِ ‎۲۰٪
 * از ارتفاعِ تصویر را می‌برید، و ۱۲۸۰×۷۲۰ لپ‌تاپِ کوتاه.
 */
for (const [width, height] of [
  [375, 812],
  [768, 1024],
  [1280, 720],
  [1440, 900],
]) {
  test(`${width}×${height}: قاب و حالتِ توضیح بی‌زوم و عنوان خوانا`, async ({ page }) => {
    await page.setViewportSize({ width, height })
    const lines = await captionContrast(page)
    for (const line of lines) {
      expect(line.contrast, `«${line.text}»`).toBeGreaterThanOrEqual(4.5)
    }

    const tile = page.locator('.gallery-marquee-item').first()
    expect(
      await tile.evaluate((el) => {
        const r = el.getBoundingClientRect()
        return Math.round((r.width / r.height) * 100) / 100
      }),
    ).toBe(0.8)

    await tile.focus()
    await page.keyboard.press('Enter')
    const img = page.getByRole('dialog').locator('img').first()
    await expect(img).toBeVisible()
    expect(
      await img.evaluate((el) => [getComputedStyle(el).objectFit, window.__zoomOf(el)]),
    ).toEqual(['contain', 1])
  })
}
