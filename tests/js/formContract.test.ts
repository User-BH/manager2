import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

/**
 * قراردادِ مشترکِ همه‌ی فرم‌ها (R69).
 *
 * ─── 🔴 چرا محافظِ ساختاری و نه بیست تستِ رفتاری ────────────────────────────
 * بیست فرم داریم. نوشتنِ نُه سناریوی برنامه‌ی کار برای هرکدام یعنی ۱۸۰
 * تست که بیشترشان **یک چیز را بارها** می‌سنجند — و مجموعه‌ای که ده دقیقه
 * طول بکشد، همان مجموعه‌ای است که کسی محلی اجرایش نمی‌کند.
 *
 * ⚠️ ولی خطرِ واقعی هم همان تکرار است: فرمِ **بیست‌ویکم** که کسی فردا
 * می‌نویسد و محافظِ ارسالِ دوباره را جا می‌اندازد.
 *
 * پس تقسیمِ کار این است:
 *   • این فایل → قاعده‌ای که باید در **همه‌ی** فرم‌ها برقرار باشد
 *   • تست‌های رفتاری → سناریوهای پرخطر روی فرم‌های نماینده
 *
 * ─── ⚠️ آنچه این محافظ **ثابت نمی‌کند** ────────────────────────────────────
 * اینکه دکمه در زمانِ اجرا واقعاً غیرفعال می‌شود. آن کارِ تستِ رفتاری است
 * (`LoginForm.test.tsx` و `UnitForm.test.tsx`). اینجا فقط تضمین می‌شود
 * که هیچ فرمی **بدونِ** این سیم‌کشی نوشته نشود.
 */

const ROOT = 'resources/js'

/** پرچم‌هایی که «همین حالا در حالِ ارسال است» را نشان می‌دهند. */
const BUSY_FLAGS = ['isSubmitting', 'saving', 'busy', 'isPending', 'submitting']

function tsxFiles(directory: string): string[] {
  const out: string[] = []

  for (const entry of readdirSync(directory)) {
    const path = join(directory, entry)

    if (statSync(path).isDirectory()) {
      out.push(...tsxFiles(path))
    } else if (entry.endsWith('.tsx')) {
      out.push(path)
    }
  }

  return out
}

function forms(): { path: string; source: string }[] {
  return tsxFiles(ROOT)
    .map((path) => ({ path, source: readFileSync(path, 'utf8') }))
    .filter(({ source }) => source.includes('useForm'))
}

describe('قراردادِ فرم‌ها', () => {
  it('فرم‌ها پیدا می‌شوند', () => {
    // ⚠️ بدونِ این، خرابی در پیمایشِ پوشه‌ها محافظ را بی‌صدا پوچ می‌کند
    expect(forms().length).toBeGreaterThanOrEqual(20)
  })

  /**
   * 🔴 هر فرم باید بداند کِی در حالِ ارسال است.
   *
   * بدونِ این پرچم، هیچ راهی برای جلوگیری از دابل‌کلیک نیست — و دابل‌کلیک
   * روی فرمِ پرداخت یا صدورِ قبض یعنی دو عملیاتِ مالیِ واقعی.
   */
  it('هر فرم پرچمِ «در حالِ ارسال» دارد', () => {
    const missing = forms()
      .filter(({ source }) => !BUSY_FLAGS.some((flag) => new RegExp(`\\b${flag}\\b`).test(source)))
      .map(({ path }) => path)

    expect(missing, `این فرم‌ها پرچمِ ارسال ندارند:\n${missing.join('\n')}`).toEqual([])
  })

  /**
   * 🔴 و آن پرچم باید واقعاً به `disabled` وصل باشد.
   *
   * ⚠️ داشتنِ پرچم و استفاده‌نکردنش بدترین حالت است: در مرورِ کد سالم به
   * نظر می‌رسد («که `isSubmitting` دارد») ولی دکمه همچنان دو بار کلیک
   * می‌شود.
   */
  it('پرچمِ ارسال به دکمه وصل است', () => {
    const ungated = forms()
      .filter(({ source }) => {
        const flags = BUSY_FLAGS.filter((flag) => new RegExp(`\\b${flag}\\b`).test(source))

        return !flags.some((flag) => new RegExp(`disabled=\\{[^}]*\\b${flag}\\b`).test(source))
      })
      .map(({ path }) => path)

    expect(ungated, `پرچمِ ارسال در این فرم‌ها به دکمه وصل نیست:\n${ungated.join('\n')}`).toEqual(
      [],
    )
  })

  /**
   * ⚠️ هر فرم باید خطای سرور را به کاربر نشان بدهد.
   *
   * فرمی که `catch` دارد و در آن سکوت می‌کند، بدترین تجربه را می‌سازد:
   * کاربر دکمه را می‌زند، چیزی نمی‌بیند، و دوباره می‌زند.
   */
  it('هر فرم خطای سرور را نشان می‌دهد', () => {
    const silent = forms()
      /*
       * ⚠️ فهرست باید **همه‌ی** راه‌های نمایشِ خطا را بشناسد.
       *
       * نسخه‌ی اولِ این الگو `toastTopError` را نمی‌شناخت و `LoginForm` را
       * متهم کرد — فرمی که دقیقاً خطا را نشان می‌دهد. محافظی که راهِ
       * درست را نشناسد، توسعه‌دهنده را وادار می‌کند دورش بزند.
       */
      /*
       * ⚠️ الگو روی **فراخوانی** لنگر دارد، نه روی نام.
       *
       * 🔴 نسخه‌ی اول دنبالِ خودِ شناسه می‌گشت و `setError,`ِ داخلِ
       * destructuring هم راضی‌اش می‌کرد — یعنی فرمی که خطاگیر را
       * می‌گرفت و هرگز صدایش نمی‌زد، از تور رد می‌شد. خرابکاری همین را
       * نشان داد.
       *
       * همان درسِ R67، در شکلی تازه: تعریف با استفاده یکی نیست.
       */
      .filter(({ source }) => !/(alertError|setError|toast\w*Error|setFormError)\s*\(/.test(source))
      .map(({ path }) => path)

    expect(silent, `این فرم‌ها خطای سرور را نمایش نمی‌دهند:\n${silent.join('\n')}`).toEqual([])
  })
})
