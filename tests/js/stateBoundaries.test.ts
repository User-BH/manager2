import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * مرزهای حالت: چه چیزی سراسری است، چه چیزی ذخیره می‌شود (مرحلهٔ ۵، فاز ۲).
 *
 * ─── آنچه اندازه‌گیری نشان داد ────────────────────────────────────────────
 * پنج استورِ Zustand، همه کوچک (۲۳ تا ۱۳۸ خط)، هیچ‌کدام `persist` ندارند،
 * و هیچ `createContext`ی در کلِ پروژه نیست.
 *
 * ⚠️ ولی دو استور **دادهٔ سرور** نگه می‌دارند: `notificationStore.items` و
 * `searchStore.results`. بندِ ۶ می‌گوید این را باید بررسی کرد، نه اینکه
 * کورکورانه جابه‌جا کرد.
 *
 * ─── نتیجهٔ بررسی، و شرطی که آن را امن نگه می‌دارد ─────────────────────────
 * 🔴 هر دو **در حافظه‌اند و روی دیسک نمی‌روند**، و خروج یک ناوبریِ واقعیِ
 * مرورگر است (`window.location.assign`) — پس کلِ حافظه با کاربرِ قبلی
 * می‌رود.
 *
 * ⚠️ یعنی امنیتِ این تصمیم به **یک خط** بند است. اگر روزی کسی خروج را به
 * ناوبریِ SPA تبدیل کند، اعلان‌ها و نتایجِ جست‌وجوی کاربرِ قبلی روی
 * دستگاهِ مشترک باقی می‌مانند و هیچ تستی قرمز نمی‌شود.
 *
 * این فایل همان یک خط را قفل می‌کند.
 */

const STORES = 'resources/js/shared/stores'

/**
 * ⚠️ کامنت‌ها پیش از هر سنجشِ متنی پاک می‌شوند.
 *
 * 🔴 تلهٔ تکرارشوندهٔ این پروژه: رشته‌ای که دنبالش می‌گردیم در **توضیحِ
 * بالای همان کد** هم آمده. بدونِ پاک‌سازی، حذفِ خودِ کد تست را قرمز
 * نمی‌کند.
 */
function codeOf(path: string): string {
  return readFileSync(path, 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/^\s*\/\/.*$/gm, '')
}

describe('مرزهای حالت', () => {
  /**
   * 🔴 خروج باید ناوبریِ واقعیِ مرورگر باشد، نه انتقالِ SPA.
   *
   * ⚠️ این تنها چیزی است که حافظهٔ استورها را با کاربرِ قبلی می‌بَرَد.
   * `navigate()`ِ react-router سندِ صفحه را نگه می‌دارد و با آن، هرچه در
   * `notificationStore` و `searchStore` مانده.
   */
  it('خروج سندِ صفحه را واقعاً عوض می‌کند', () => {
    const code = codeOf('resources/js/shared/layout/UserMenu.tsx')

    // از فاز ۸ِ مرحلهٔ ۵ از راهِ `leaveToAuth` تا خروج فقط یک ناوبری بسازد
    expect(code).toContain('leaveToAuth()')
    expect(codeOf('resources/js/shared/lib/leaveToAuth.ts')).toContain('window.location.replace')

    // ⚠️ و نباید با ناوبریِ درون‌برنامه‌ای جایگزین شده باشد
    expect(code).not.toMatch(/navigate\(\s*['"]\/auth/)
  })

  /**
   * 🔴 هیچ استوری روی دیسک نمی‌نویسد.
   *
   * ⚠️ لحظه‌ای که یکی از این استورها `persist` بگیرد، دادهٔ سرورِ کاربر
   * وارد `localStorage` می‌شود و دیگر با خروج پاک نمی‌شود — همان چیزی که
   * بندِ ۷ ممنوع کرده.
   */
  it('هیچ استوری persist ندارد', () => {
    const offenders: string[] = []

    for (const file of readdirSync(STORES)) {
      if (!file.endsWith('.ts')) continue

      const code = codeOf(join(STORES, file))

      if (/\bpersist\s*\(/.test(code) || /zustand\/middleware/.test(code)) offenders.push(file)
    }

    expect(offenders).toEqual([])
  })

  /**
   * ⚠️ سنجهٔ اندازه برای استورها.
   *
   * 🔴 بندِ ۵ می‌گوید استور نباید بیش از حد بزرگ شود. اندازهٔ امروز
   * ۲۳ تا ۱۳۸ خط است؛ سقف با فضای رشد گذاشته شده تا هشدار پیش از
   * تبدیل‌شدنِ استور به «انبارِ همه‌چیز» بیاید.
   */
  it('هیچ استوری به انبارِ همه‌چیز تبدیل نشده', () => {
    const tooBig: string[] = []

    for (const file of readdirSync(STORES)) {
      if (!file.endsWith('.ts')) continue

      const lines = readFileSync(join(STORES, file), 'utf8').split('\n').length

      if (lines > 220) tooBig.push(`${file} (${lines} خط)`)
    }

    expect(tooBig).toEqual([])
  })

  /**
   * 🔴 «مرا به خاطر بسپار» فقط شماره را نگه می‌دارد.
   *
   * ─── چرا این ادعا لازم است ─────────────────────────────────────────────
   * ⚠️ شماره PII است ولی **نامِ کاربری** است، نه رمز — و مرورگرها خودشان
   * همین کار را می‌کنند. آنچه واقعاً ممنوع است، سُر خوردنِ رمز یا توکن یا
   * کدِ یک‌بارمصرف به همان رکورد است.
   *
   * تستِ رفتاری‌اش در `rememberMe.test.ts` هست؛ اینجا **شکلِ داده** قفل
   * می‌شود.
   */
  it('حافظهٔ ورود هیچ اعتبارنامه‌ای نگه نمی‌دارد', () => {
    const code = codeOf('resources/js/shared/lib/rememberMe.ts')

    for (const secret of ['password', 'token', 'otp', 'code', 'secret']) {
      expect(code.toLowerCase(), `«${secret}» نباید در حافظهٔ ورود باشد.`).not.toContain(secret)
    }

    // ⚠️ و ادعای مثبت: همان دو فیلدی که باید باشند، هستند
    expect(code).toContain('phone')
    expect(code).toContain('expiresAt')
  })

  /**
   * ⚠️ هیچ Context APIی در پروژه نیست — و این تصمیم است، نه اتفاق.
   *
   * 🔴 بندِ ۱۰ نگرانِ Contextی است که با هر تغییر، درختِ بزرگی را رندر
   * کند. تا وقتی Context نداریم، آن ریسک صفر است. اگر روزی اضافه شد،
   * این تست قرمز می‌کند تا **آگاهانه** دربارهٔ دامنه‌اش تصمیم گرفته شود.
   */
  it('Context تازه بدونِ تصمیم اضافه نمی‌شود', () => {
    const withContext: string[] = []

    const walk = (dir: string): void => {
      for (const entry of readdirSync(dir, { withFileTypes: true })) {
        const path = join(dir, entry.name)

        if (entry.isDirectory()) walk(path)
        else if (/\.(ts|tsx)$/.test(entry.name) && /createContext/.test(codeOf(path))) {
          withContext.push(path)
        }
      }
    }

    walk('resources/js')

    expect(withContext).toEqual([])
  })
})
