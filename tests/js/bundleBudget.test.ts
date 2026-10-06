import { execFileSync } from 'node:child_process'
import { existsSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * بودجه‌ی باندل (R36).
 *
 * ─── چرا عدد و نه «حواسمان باشد» ───────────────────────────────────────────
 * سنگین‌شدنِ باندل هیچ خطایی نمی‌دهد و در هیچ تستی نمی‌شکند. یک `import`
 * ایستا به‌جای پویا کافی است تا صد کیلوبایت به بارگذاریِ اول اضافه شود و
 * ماه‌ها کسی نفهمد. تنها راهِ نگه‌داشتنش، سقفِ عددی است.
 *
 * ─── این سقف‌ها از کجا آمده‌اند ────────────────────────────────────────────
 * از اندازه‌گیریِ واقعیِ پس از R36، به‌اضافه‌ی حدودِ ۱۰٪ فضا برای رشدِ
 * طبیعیِ کد. سقف‌ها **کاهشی**‌اند: اگر کاری باندل را کوچک‌تر کرد، سقف هم
 * باید پایین بیاید، وگرنه فضای آزادش بی‌سروصدا دوباره پر می‌شود.
 */

/** بیشینه‌ی بایتِ فشرده در بارگذاریِ اولِ هر ورودی. */
const BUDGET: Record<string, number> = {
  'resources/js/app/entries/home.tsx': 182 * 1024,
  'resources/js/app/entries/auth.tsx': 209 * 1024,
  'resources/js/app/entries/demo.tsx': 178 * 1024,
  /*
   * مرحلهٔ ۷ · فاز ۴: ‎۱۷۶ ← ‎۱۷۸. اندازه‌گیری ‎۱۷۶٫۲KB؛ رشد **متن** است، نه کد:
   * دو پرسشِ تازه و پاسخ‌های اصلاح‌شده‌ی دقیق‌تر (همه با کد سنجیده). فرمِ
   * بازخورد با zod و react-hook-form عمداً تنبل است — بی‌آن ‎۲۰۸KB بود.
   */
  'resources/js/app/entries/support.tsx': 178 * 1024,
  'resources/js/app/main.tsx': 174 * 1024,
  // بلاگ (مرحلهٔ ۶ · فاز ۱۱): اندازه‌گیری ‎۱۶۴٫۶KB، به‌اضافه‌ی حدودِ ۱۰٪
  'resources/js/app/entries/blog.tsx': 181 * 1024,
}

/** بیشینه‌ی بایتِ **یکتا** در کلِ نشست (کاربری که هر پنج صفحه را ببیند). */
const SESSION_BUDGET = 272 * 1024

/**
 * ⚠️ سقفِ فایل‌های ریز.
 *
 * پیش از R36، بیلد ۷۰ فایلِ زیرِ دو کیلوبایت تولید می‌کرد (مجموعاً ‎۲۲٫۵KB)
 * چون tree-shaking هر آیکونِ lucide را چانکِ خودش می‌کرد. سربارِ هدر و
 * رفت‌وبرگشتِ هر درخواست از خودِ محتوا بیشتر می‌شد.
 */
const MAX_TINY_CHUNKS = 24

/**
 * سقفِ **تعدادِ درخواست** (R65).
 *
 * ⚠️ حجم تنها نیمی از داستان است. سی چانکِ ده‌کیلوبایتی و یک چانکِ
 * سیصدکیلوبایتی حجمِ برابر دارند و روی شبکه‌ی موبایل کاملاً متفاوت رفتار
 * می‌کنند: هر درخواست هزینه‌ی رفت‌وبرگشتِ خودش را دارد و بودجه‌ی بایتی
 * هیچ‌وقت آن را نشان نمی‌دهد.
 *
 * اندازه‌گیریِ R65: بیشترین چانکِ یک ورودی ۱۵ (صفحه‌ی فرود)، و ۲۴ فایلِ
 * یکتا در نشستِ پنج‌صفحه‌ای.
 *
 * ⚠️ ۱۷ → ۱۸ (مرحلهٔ ۷ · فاز ۵) با دلیلِ سنجیده، نه برای سبز شدن: پیشنهادِ
 * نصب حالا بینِ داشبورد و چهار صفحه‌ی عمومی مشترک است و چانکِ مشترکِ خودش
 * را گرفت (`InstallPrompt`، ‎۱٫۰KB: شنونده‌ی رویدادِ مرورگر و زمان‌سنج؛
 * خودِ کارت تنبل است و در شمارش نیست). rolldown چانک‌های هم‌مجموعه را
 * یکی نمی‌کند (`HomeNavbar` و `Logo` هم همین‌اند)؛ تنها راهِ نگه‌داشتنِ ۱۷
 * تکرارِ همان منطق در دو جا یا گذاشتنش در بشکه‌ی `hooks` بود که صفحه‌ی ورود
 * را هم بی‌دلیل سنگین می‌کرد. حجمِ بایتی همه زیرِ سقفِ خودشان ماند.
 */
const MAX_CHUNKS_PER_ENTRY = 18
/*
 * ⚠️ ۲۶ → ۳۰ (مرحلهٔ ۶ · فاز ۱۱) با دلیلِ سنجیده، نه برای سبز شدن: بلاگ
 * **ششمین** صفحه است (خودِ ورودی)، و `HomeFooter` و `clipboard` که حالا دو
 * صفحه مصرفشان می‌کنند چانکِ مشترکِ خودشان شدند. اندازه‌گیری: ۲۹ فایل؛
 * بایتِ یکتای نشست (‎۲۵۸٫۶KB) هنوز زیرِ سقفِ ۲۷۲KB است.
 */
/*
 * ⚠️ ۳۰ → ۳۱ (مرحلهٔ ۹ · فاز ۳) با تصمیمِ کارفرما و اندازه‌گیری: قاعده‌ی «نامِ
 * فارسی» (`shared/lib/personName`) بینِ فرمِ ثبت‌نام (صفحه‌ی ورود) و فرمِ
 * پروفایلِ چهار داشبورد مشترک شد و rolldown چانکِ خودش را ساخت (‎۷۰۶ بایت). راهِ
 * دیگر نسخه‌ی سومِ همان قاعده در کلاینت بود. بایتِ یکتای نشست ‎۲۷۶٫۰KB، زیرِ سقف.
 *
 * ⚠️ اندازه‌گیری پس از `php artisan view:clear`: `app.css` ویوهای کامپایل‌شده‌ی
 * `storage/framework/views` را هم می‌پیماید و کشِ محلی ‎۳٫۴KB به CSS می‌افزود —
 * بیلدِ CI (و نسخه‌ی تمیز) آن را ندارد.
 */
const MAX_SESSION_FILES = 31

interface Report {
  entry: string
  gzip: number
  chunkCount?: number
  fileCount?: number
  tinyChunks?: number
  files?: Array<{ file: string; raw: number; gzip: number }>
}

function analyze(): Report[] {
  return JSON.parse(
    execFileSync('node', ['scripts/analyze-bundle.mjs', '--json'], { encoding: 'utf8' }),
  ) as Report[]
}

/*
 * ⚠️ بدونِ `public/build` این تست‌ها **پوچ** می‌شوند، نه قرمز.
 *
 * `describe.skipIf` تنها راهی است که هم روی ماشینی که هنوز بیلد نکرده
 * نمی‌شکند و هم در CI — که همیشه بیلد دارد — واقعاً اجرا می‌شود. اگر
 * به‌جایش `try/catch` می‌گذاشتم، رد‌شدنِ خاموش از سقف‌ها تبدیل به حالتِ
 * عادی می‌شد.
 */
const built = existsSync('public/build/manifest.json')

describe.skipIf(!built)('بودجه‌ی باندل', () => {
  const report = built ? analyze() : []

  for (const [entry, budget] of Object.entries(BUDGET)) {
    it(`${entry} از سقفش نمی‌گذرد`, () => {
      const item = report.find((row) => row.entry === entry)

      expect(item, `ورودیِ «${entry}» در بیلد نیست`).toBeDefined()
      expect(
        item!.gzip,
        `${entry}: ${(item!.gzip / 1024).toFixed(1)}KB > سقفِ ${(budget / 1024).toFixed(0)}KB`,
      ).toBeLessThanOrEqual(budget)
    })
  }

  it('بایتِ یکتای کلِ نشست از سقف نمی‌گذرد', () => {
    const session = report.find((row) => row.entry === '__session__')

    expect(session).toBeDefined()
    expect(session!.gzip).toBeLessThanOrEqual(SESSION_BUDGET)
  })

  it('هیچ ورودی‌ای بیش از سقف، درخواست نمی‌سازد', () => {
    for (const item of report) {
      if (item.entry === '__session__') continue

      expect(
        item.chunkCount,
        `${item.entry}: ${item.chunkCount} چانک > سقفِ ${MAX_CHUNKS_PER_ENTRY}`,
      ).toBeLessThanOrEqual(MAX_CHUNKS_PER_ENTRY)
    }
  })

  it('تعدادِ فایلِ یکتای کلِ نشست از سقف نمی‌گذرد', () => {
    const session = report.find((row) => row.entry === '__session__')

    expect(session!.fileCount).toBeLessThanOrEqual(MAX_SESSION_FILES)
  })

  it('بیلد پر از فایلِ ریز نمی‌شود', () => {
    const session = report.find((row) => row.entry === '__session__')

    expect(session!.tinyChunks).toBeLessThanOrEqual(MAX_TINY_CHUNKS)
  })

  /**
   * ⚠️ کتابخانه‌های سنگین نباید در بارگذاریِ **اولِ** هیچ صفحه‌ای باشند.
   *
   * ─── چرا این تست بعد از پاسِ خرابکاری اضافه شد ──────────────────────────
   * تستِ PHPیِ متناظر، شکلِ `import` را در سورس می‌سنجید — و سه خرابکاری از
   * کنارش رد شدند:
   *  • `import { TrendChart } from './components/TrendChart'` — خودِ کتابخانه
   *    را نام نمی‌برد ولی Recharts را **با واسطه** می‌آورد.
   *  • برگرداندنِ `<TestimonialsSection />` ایستا — همان داستان با Swiper.
   *  • `import Swal from 'sweetalert2'` در یک فایلِ `.ts` — که آن تست اصلاً
   *    اسکن نمی‌کرد (فقط `.tsx` و `.php` می‌دید).
   *
   * سنجیدنِ خروجیِ واقعیِ بیلد از سنجیدنِ شکلِ نوشتار محکم‌تر است: هر راهی
   * که کتابخانه به بارگذاریِ اول برسد، اینجا دیده می‌شود.
   */
  it('کتابخانه‌های سنگین در بارگذاریِ اول نیستند', () => {
    // هر کتابخانه پیشوندِ کلاسِ خودش را داخلِ JS جا می‌گذارد، حتی پس از فشرده‌سازی
    const markers: Record<string, string> = {
      recharts: 'recharts-wrapper',
      swiper: 'swiper-slide',
      sweetalert2: 'swal2-container',
    }

    for (const item of report) {
      if (item.entry === '__session__') continue

      for (const file of item.files ?? []) {
        /*
         * ⚠️ فقط JS.
         *
         * `app.css` خودمان کلاس‌های `swal2-*` را دارد — همان‌جا که دیالوگ‌ها
         * را با توکن‌های تمِ پروژه هم‌رنگ می‌کنیم. آن‌ها **استایلِ ما**یند نه
         * خودِ کتابخانه، و چند صد بایتِ CSS با ‎۷۸KB جاوااسکریپت یکی نیست.
         */
        if (!file.file.endsWith('.js')) continue

        const source = readFileSync(join('public/build', file.file), 'utf8')

        for (const [library, marker] of Object.entries(markers)) {
          expect(
            source.includes(marker),
            `${item.entry} → ${file.file} حاوی «${library}» است؛ باید تنبل بار شود.`,
          ).toBe(false)
        }
      }
    }
  })
})
