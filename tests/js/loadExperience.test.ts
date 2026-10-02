import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * تجربه‌ی واقعیِ بارگذاری، سمتِ کاربر (مرحلهٔ ۷ · فاز ۳).
 *
 * هر سه موردِ زیر در مرورگرِ واقعی سنجیده شدند و هیچ‌کدام خطا نمی‌دادند —
 * صفحه فقط «می‌پرید» یا «دیر می‌آمد». چیزی که خطا نمی‌دهد بی‌صدا برمی‌گردد.
 */

function codeOf(path: string): string {
  return readFileSync(path, 'utf8')
}

function filesUnder(dir: string, ext: RegExp): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    return statSync(path).isDirectory() ? filesUnder(path, ext) : ext.test(name) ? [path] : []
  })
}

describe('جابه‌جاییِ صفحه (CLS)', () => {
  /*
   * 🔴 بی‌`initial={false}`، framer-motion عرضِ سایدبار را در اولین رندر از
   * اندازه‌ی طبیعیِ محتوا تا ۲۷۲ انیمیت می‌کرد: CLSِ ‎۰٫۰۱۴ در **همه‌ی**
   * صفحه‌های داشبورد در دسکتاپ (پس از اصلاح ‎۰٫۰۰۰۱).
   */
  it('سایدبار در اولین رندر عرضش را انیمیت نمی‌کند', () => {
    const sidebar = codeOf('resources/js/shared/layout/Sidebar.tsx')

    expect(sidebar).toMatch(/initial=\{false\}\s*animate=\{\{ width:/)
  })

  it('جای نوارِ اسکرول از اول رزرو است', () => {
    const css = codeOf('resources/css/app.css')

    expect(css).toMatch(/html \{[^}]*scrollbar-gutter: stable;[^}]*\}/)
  })

  /*
   * 🔴 انتخابگرِ دوره فقط پس از رسیدنِ داده رندر می‌شد؛ در موبایل ردیفِ
   * سرصفحه دوخطی می‌شد و کلِ صفحه ‎۵۷px پایین می‌پرید.
   */
  it('هیچ کنترلی فقط پس از رسیدنِ داده در سرصفحه ظاهر نمی‌شود', () => {
    const offenders = filesUnder('resources/js/features', /\.tsx$/).filter((path) =>
      /\{data && \(\s*<select/.test(codeOf(path)),
    )

    expect(offenders).toEqual([])
  })

  /*
   * 🔴 سنجش روی موبایلِ کند (CLS ‎۰٫۰۶۶): «در حال بارگذاری…» دکمه‌ها را به
   * سطرِ دوم می‌برد، نامِ کوتاه‌ترِ دوره بالا می‌آوردشان و دکمه‌ی PDF دوباره
   * پایین — صفحه دو بار پرید. پس از اصلاح ‎۰٫۰۰۲۷.
   */
  it('سرصفحه‌ی قبض‌ها به پهنای متن و رسیدنِ داده بسته نیست', () => {
    const bills = codeOf('resources/js/features/billing/bills/BillsPage.tsx')

    expect(bills).toContain('<header className="flex flex-col gap-3 sm:flex-row')
    expect(bills).not.toMatch(/\{data && data\.data\.length > 0 && \(/)
  })
})

describe('تصویرِ بالای صفحه‌ی ورود', () => {
  const auth = codeOf('resources/js/features/auth/AuthPage.tsx')

  it('تنبل نیست و اولویتِ بالا دارد', () => {
    const panel = auth.slice(auth.indexOf('const brandPanel'))
    const image = panel.slice(panel.indexOf('<img'), panel.indexOf('/>', panel.indexOf('<img')))

    expect(image).toContain('loading="eager"')
    expect(image).toContain('fetchPriority="high"')
    expect(image).not.toContain('loading="lazy"')
  })

  it('فقط در اندازه‌ای که دیده می‌شود دانلود می‌شود', () => {
    // زیرِ lg پنل پنهان است؛ تصویرِ فوری آنجا ‎~۱۲۰KB بی‌مصرف بود
    expect(auth).toMatch(/<source media="\(min-width: 1024px\)" srcSet=\{authBackgroundImage\} \/>/)
  })
})
