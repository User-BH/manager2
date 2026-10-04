import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, sep } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * توکن‌های طراحی، RTL، حالتِ تیره و قالب‌بندیِ مرکزی (مرحلهٔ ۵، فاز ۵).
 *
 * ─── آنچه اندازه‌گیری نشان داد ────────────────────────────────────────────
 *   `text-[Npx]` دلخواه            → ۵۱۶ مورد با ۱۸ مقدارِ متمایز
 *   رنگِ hex در داشبورد            → ۶ فایل با رنگِ وضعیتِ ثابت (ضدِ حالتِ تیره)
 *   `formatMoney`                  → ۱۸ فایل؛ یک دورزدنِ غیرمالی
 *   `MotionConfig reducedMotion`   → هر سه ورودی
 *   `<html dir="rtl">`             → هر دو قالب
 *
 * ⚠️ رنگ‌های وضعیت در همین فاز به توکن رفتند. مقادیرِ دلخواهِ فونت
 * **مهاجرت نکردند** — تستِ بصری‌ای نداریم که اشتباهِ اندازه را بگیرد — ولی
 * ratchet شدند: می‌توانند کم شوند، نمی‌توانند زیاد شوند.
 */

const ROOT = 'resources/js'
const norm = (p: string): string => p.split(sep).join('/')

function sources(): string[] {
  const found: string[] = []

  const walk = (dir: string): void => {
    for (const entry of readdirSync(dir)) {
      const path = join(dir, entry)

      if (statSync(path).isDirectory()) walk(path)
      else if (/\.tsx$/.test(path)) found.push(norm(path))
    }
  }

  walk(ROOT)

  return found
}

/** ⚠️ کامنت‌ها پاک می‌شوند — تلهٔ تکرارشوندهٔ این پروژه. */
function codeOf(path: string): string {
  return readFileSync(path, 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/^\s*\/\/.*$/gm, '')
    .replace(/\{\/\*[\s\S]*?\*\/\}/g, '')
}

const files = sources().map((path) => ({ path: path.replace(`${ROOT}/`, ''), code: codeOf(path) }))
const css = readFileSync('resources/css/app.css', 'utf8')

describe('توکن‌های طراحی', () => {
  it('فایل‌ها واقعاً خوانده شده‌اند', () => {
    expect(files.length).toBeGreaterThan(80)
  })

  /**
   * 🔴 ratchetِ اندازهٔ فونتِ دلخواه (بند ۴۷).
   *
   * ⚠️ مجموعهٔ مقادیر امروز ثبت شده. مقدارِ تازه (مثلاً `text-[12.7px]`) یعنی
   * یکی دیگر خارج از مقیاس — و دقیقاً همان چیزی است که مقیاس را بی‌معنا
   * می‌کند. مقدارِ موجود آزاد است؛ مقدارِ تازه تصمیم می‌خواهد.
   */
  it('اندازهٔ فونتِ دلخواهِ تازه‌ای وارد نمی‌شود', () => {
    const known = new Set([
      '9.5',
      '10',
      '10.5',
      '11',
      '11.5',
      '12',
      '12.5',
      '13',
      '13.5',
      '14',
      '14.5',
      '15',
      '16',
      '17',
      '19',
      '22',
      '24',
      '26',
    ])

    const novel = new Set<string>()

    for (const { code } of files) {
      for (const m of code.matchAll(/\btext-\[([0-9.]+)px\]/g)) {
        if (!known.has(m[1] ?? '')) novel.add(m[1] ?? '')
      }
    }

    expect([...novel]).toEqual([])
  })

  /**
   * ⚠️ و شمارِ کل فقط پایین می‌آید.
   *
   * 🔴 سقف روی عددِ امروز است، نه یک عددِ سخاوتمندانه. با هر مهاجرت به
   * توکن‌های `--text-*`، این عدد باید دستی پایین بیاید — وگرنه فضای
   * آزادشده بی‌صدا دوباره پر می‌شود (همان درسِ بودجهٔ باندل).
   */
  it('شمارِ اندازه‌های دلخواه بزرگ‌تر نمی‌شود', () => {
    const total = files.reduce(
      (sum, { code }) => sum + (code.match(/\btext-\[[0-9.]+px\]/g) ?? []).length,
      0,
    )

    expect(total).toBeLessThanOrEqual(516)
  })

  /** ⚠️ توکن‌های تایپ که مهاجرت باید به آن‌ها برسد، واقعاً تعریف شده‌اند. */
  it('مقیاسِ تایپ در @theme ثبت شده', () => {
    for (const token of [
      '--text-micro',
      '--text-tiny',
      '--text-small',
      '--text-body',
      '--text-reading',
    ]) {
      expect(css, `${token} در app.css نیست.`).toContain(`${token}:`)
    }
  })

  /**
   * 🔴 هیچ رنگِ وضعیتی در داشبورد hex نیست (بند ۴۹).
   *
   * ─── چه چیزی باز بود ───────────────────────────────────────────────────
   * `StatusBadge` و پنج فایلِ دیگر رنگِ وضعیت را hex نوشته بودند
   * (`#475569`, `#b45309`, …). در حالتِ تیره روی پس‌زمینهٔ تیره می‌نشستند و
   * کنتراست می‌رفت — و چون فقط در تمِ تیره رخ می‌داد، هیچ تستی نمی‌دیدش.
   *
   * ⚠️ در همان حال `app.css` نظامِ کاملِ `--tone-*` و `--state-*` داشت که در
   * `.dark` بازتعریف می‌شود. hexها آن را دور می‌زدند.
   *
   * ─── معافیت‌های نام‌برده ────────────────────────────────────────────────
   * چند رنگ **واقعاً** مستقل از تم‌اند و با دلیل معاف شده‌اند.
   */
  it('هیچ رنگِ وضعیتِ ثابتی در داشبورد نیست', () => {
    const allowed: Record<string, string> = {
      'shared/common/ContactAvatar.tsx':
        'پالتِ حروفِ اول؛ رنگِ هویت است و در هر دو تم یکسان می‌ماند.',
      'shared/layout/NotificationBell.tsx': 'رنگِ سایه؛ سایه در تمِ تیره هم تیره است.',
      'features/finance/good-payers/GoodPayersPage.tsx': 'برنزِ مدال؛ معنایی است نه وضعیتی.',
      'features/registrant/RegistrantDashboardPage.tsx':
        'فقط fallbackِ `var(--color-amber-400, #fbbf24)`.',
    }

    const offenders: string[] = []

    for (const { path, code } of files) {
      if (path.startsWith('features/landing/')) continue
      if (path in allowed) continue

      // ⚠️ `#fff` روی پس‌زمینهٔ برند در هر دو تم درست است
      const hexes = (code.match(/#[0-9a-fA-F]{6}\b/g) ?? []).filter(
        (h) => h.toLowerCase() !== '#ffffff',
      )

      if (hexes.length > 0) offenders.push(`${path}: ${[...new Set(hexes)].join(' ')}`)
    }

    expect(offenders).toEqual([])
  })

  /**
   * ⚠️ هر توکنِ tone و state که در روشن هست، در تیره هم هست.
   *
   * 🔴 توکنی که فقط در `:root` باشد، در حالتِ تیره همان رنگِ روشن را
   * می‌گیرد — یعنی دقیقاً همان مشکلِ hex، فقط با اسمِ بهتر.
   */
  it('هر توکنِ رنگِ وضعیت در حالتِ تیره هم تعریف شده', () => {
    const light = new Set(css.match(/--(?:tone|state)-[a-z-]+(?=:)/g) ?? [])
    const darkBlock = /\.dark\s*\{[\s\S]*?\n\}/.exec(css)?.[0] ?? ''
    const dark = new Set(darkBlock.match(/--(?:tone|state)-[a-z-]+(?=:)/g) ?? [])

    expect(light.size).toBeGreaterThan(10)

    const missing = [...light].filter((token) => !dark.has(token))

    expect(missing).toEqual([])
  })

  /** ⚠️ و توکنِ تازهٔ همین فاز هم در هر دو تم هست. */
  it('توکنِ accent در هر دو تم تعریف شده', () => {
    expect(css.match(/--tone-accent-fg:/g)?.length).toBe(2)
    expect(css.match(/--tone-accent-bg:/g)?.length).toBe(2)
  })

  /**
   * 🔴 RTL روی ریشهٔ سند است، نه روی تک‌تک عناصر (بند ۴۲).
   *
   * ⚠️ کلاس‌های جهت‌دارِ فیزیکی (`mr-`, `right-`) در این پروژه **اشتباه
   * نیستند**: برنامه فقط RTL است و همه‌شان با هم سازگارند. آنچه نباید عوض
   * شود، خودِ `dir="rtl"` روی `<html>` است — بدونِ آن، همهٔ آن کلاس‌ها
   * یک‌باره برعکس می‌شوند.
   */
  it('هر دو قالب dir="rtl" روی html دارند', () => {
    for (const view of ['resources/views/spa.blade.php', 'resources/views/offline.blade.php']) {
      expect(readFileSync(view, 'utf8'), `${view} بدونِ dir="rtl"`).toMatch(/<html[^>]*dir="rtl"/)
    }
  })

  /**
   * 🔴 مبلغ فقط از `formatMoney` می‌آید (بند ۴۳).
   *
   * ⚠️ قالب‌بندیِ مرکزی از قبل بود و ۱۸ فایل از آن استفاده می‌کنند. تنها
   * `toLocaleString`ِ بیرون از `format.ts`، شمارندهٔ متحرکِ آمارِ صفحهٔ
   * اصلی است — عدد است، نه پول — و با دلیل معاف شده.
   */
  it('هیچ کامپوننتی مبلغ را خودش قالب‌بندی نمی‌کند', () => {
    const allowed = ['features/landing/home/components/StatsSection.tsx']

    const offenders = files
      .filter(({ path }) => !allowed.includes(path))
      .filter(({ code }) => /toLocaleString\(|Intl\.NumberFormat/.test(code))
      .map(({ path }) => path)

    expect(offenders).toEqual([])
  })

  /**
   * ⚠️ تاریخ فقط از `format.ts` می‌آید (بند ۴۴).
   *
   * 🔴 راهبردِ تاریخ یکی است: شمسی، با `formatJalaliDate` و خواهرانش.
   * `toLocaleDateString` درجا یعنی یک جا میلادی، یک جا با منطقهٔ زمانیِ
   * دیگر — همان چیزی که بند ۴۴ ممنوع کرده.
   */
  it('هیچ کامپوننتی تاریخ را خودش قالب‌بندی نمی‌کند', () => {
    const offenders = files
      .filter(({ code }) =>
        /toLocaleDateString\(|toLocaleTimeString\(|Intl\.DateTimeFormat/.test(code),
      )
      .map(({ path }) => path)

    expect(offenders).toEqual([])
  })

  /**
   * ⚠️ کاهشِ حرکت در هر سه ورودی فعال است (بند ۳۶).
   *
   * 🔴 `MotionConfig reducedMotion="user"` یعنی framer-motion خودش تنظیمِ
   * سیستم‌عامل را می‌خواند و انیمیشنِ جابه‌جایی را حذف می‌کند. بدونِ آن،
   * هر `motion.div` باید جداگانه بررسی می‌کرد — و یکی حتماً جا می‌ماند.
   */
  it('هر ورودی کاهشِ حرکت را رعایت می‌کند', () => {
    for (const entry of ['app/main.tsx', 'app/entries/auth.tsx', 'app/entries/demo.tsx']) {
      const file = files.find((f) => f.path === entry)

      expect(file, `${entry} پیدا نشد`).toBeDefined()
      expect(file?.code).toMatch(/reducedMotion="user"/)
    }
  })
})
