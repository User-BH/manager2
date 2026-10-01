import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, sep } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * ایمنیِ نوع و مرزهای ورودی (مرحلهٔ ۵، فاز ۳ — بندهای ۱۳ تا ۱۸).
 *
 * ─── آنچه اندازه‌گیری نشان داد ────────────────────────────────────────────
 *   `any` / `as any`                → ۰
 *   نتیجهٔ استفاده‌شده بدونِ نوع     → ۰ (هر ۸۱ فراخوانیِ دارای نتیجه، نوع دارد)
 *   `queryFn` بدونِ `signal`        → ۱ از ۳۹
 *   `JSON.parse(...) as T`          → ۶ مورد، دو تای‌شان بدونِ هیچ بررسی
 *   ادعای دولایه (`as unknown as`)  → ۱
 *
 * ⚠️ یعنی وضعیت خوب بود ولی سه سوراخِ واقعی داشت. این فایل هر سه را
 * می‌بندد تا برنگردند.
 */

const ROOT = 'resources/js'
const norm = (p: string): string => p.split(sep).join('/')

function sources(): string[] {
  const found: string[] = []

  const walk = (dir: string): void => {
    for (const entry of readdirSync(dir)) {
      const path = join(dir, entry)

      if (statSync(path).isDirectory()) walk(path)
      else if (/\.(ts|tsx)$/.test(path) && !/\.d\.ts$/.test(path)) found.push(norm(path))
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
}

const files = sources().map((path) => ({ path: path.replace(`${ROOT}/`, ''), code: codeOf(path) }))

describe('ایمنیِ نوع', () => {
  it('فایل‌ها واقعاً خوانده شده‌اند', () => {
    expect(files.length).toBeGreaterThan(150)
  })

  /**
   * 🔴 `any` یعنی خاموش‌کردنِ کلِ سیستمِ نوع برای آن مقدار.
   *
   * ⚠️ `unknown` عمداً ممنوع نیست: در `catch` و مرزهای خارجی **درست** است،
   * چون مجبورت می‌کند پیش از استفاده بررسی کنی. تفاوتشان همین است.
   */
  it('هیچ `any`ی در کد نیست', () => {
    const offenders = files
      .filter(({ code }) => /:\s*any\b|\bas\s+any\b|<any>/.test(code))
      .map(({ path }) => path)

    expect(offenders).toEqual([])
  })

  /**
   * 🔴 ادعای دولایه، صریح‌ترین شکلِ دروغ به کامپایلر است.
   *
   * ─── موردی که همین فاز بست ─────────────────────────────────────────────
   * `ReceiptUploadForm` می‌نوشت `undefined as unknown as File` تا فیلدی را
   * که هنوز وجود ندارد «موجود» جا بزند.
   *
   * ⚠️ `as unknown as X` همیشه یعنی «می‌دانم نوعش این نیست، ولی وانمود
   * کن». اگر روزی واقعاً لازم شد، باید تصمیمی دیده‌شونده باشد نه یک خط
   * بی‌صدا.
   */
  it('هیچ ادعای دولایه‌ای وجود ندارد', () => {
    const offenders = files
      .filter(({ code }) => /\bas\s+unknown\s+as\b/.test(code))
      .map(({ path }) => path)

    expect(offenders).toEqual([])
  })

  /**
   * 🔴 `JSON.parse(...) as T` — ادعا به‌جای بررسی.
   *
   * ⚠️ هرجا JSON از بیرون می‌آید (`localStorage`، تگِ رندرشده، پیامِ
   * خارجی)، شکلش **تضمینی نیست**. `as T` فقط کامپایلر را ساکت می‌کند و
   * دادهٔ خراب را مستقیم به رندر می‌فرستد.
   *
   * ─── معافیت‌های نام‌برده ────────────────────────────────────────────────
   * سه فایل بلافاصله پس از `parse` بررسی می‌کنند و همان‌جا امن‌اند. با
   * دلیل معاف شده‌اند تا سیاهه به فهرستِ نخوانده تبدیل نشود.
   */
  it('هیچ JSON.parseِ بی‌بررسی وجود ندارد', () => {
    const allowed: Record<string, string> = {
      'shared/lib/rememberMe.ts': 'بلافاصله پس از parse، هر دو فیلد بررسی می‌شوند.',
      'shared/lib/viewer.ts':
        'به `{ authenticated?: unknown }` تبدیل می‌شود و با `=== true` سنجیده.',
      'shared/lib/observability/config.ts': 'تگِ رندرشدهٔ خودِ سرور است، نه ورودیِ کاربر.',
    }

    const offenders = files
      .filter(({ code }) => /JSON\.parse\([^)]*\)\s+as\s+/.test(code))
      .map(({ path }) => path)
      .filter((path) => !(path in allowed))

    expect(offenders).toEqual([])
  })

  /**
   * ⚠️ و معافیت‌ها کهنه نمی‌مانند.
   *
   * 🔴 اگر فایلی دیگر `JSON.parse ... as` نداشته باشد، ماندنش در سیاهه
   * یعنی سیاهه دارد بی‌معنا می‌شود.
   */
  it('هر معافیتِ JSON.parse هنوز واقعی است', () => {
    const allowed = [
      'shared/lib/rememberMe.ts',
      'shared/lib/viewer.ts',
      'shared/lib/observability/config.ts',
    ]

    const actual = files
      .filter(({ code }) => /JSON\.parse\([^)]*\)\s+as\s+/.test(code))
      .map(({ path }) => path)

    const stale = allowed.filter((path) => !actual.includes(path))

    expect(stale).toEqual([])
  })

  /**
   * 🔴 هر درخواستِ TanStack Query باید `signal` بگیرد.
   *
   * ─── موردی که همین فاز بست ─────────────────────────────────────────────
   * ۳۸ از ۳۹ `queryFn` سیگنال می‌دادند؛ `InitialDashboard` نمی‌داد. یعنی
   * آن یک درخواست با unmount یا تغییرِ مسیر **لغو نمی‌شد** و پاسخش روی
   * صفحه‌ای می‌نشست که کاربر دیگر آنجا نبود.
   *
   * ⚠️ بندِ ۱۸ دقیقاً همین را می‌خواهد: لغو هنگام Route Change و
   * Component Unmount.
   */
  it('هر queryFn سیگنالِ لغو می‌گیرد', () => {
    const offenders: string[] = []

    for (const { path, code } of files) {
      for (const line of code.split('\n')) {
        if (!line.includes('queryFn:')) continue
        if (line.includes('({ signal })')) continue

        offenders.push(`${path}: ${line.trim()}`)
      }
    }

    expect(offenders).toEqual([])
  })

  /**
   * ⚠️ و ادعای مثبت: `queryFn`ها واقعاً وجود دارند.
   *
   * 🔴 بدونِ این، تستِ بالا روی صفر موردِ پیداشده هم سبز می‌شود.
   */
  it('queryFnها واقعاً پیدا شده‌اند', () => {
    const count = files.reduce((sum, { code }) => sum + (code.match(/queryFn:/g) ?? []).length, 0)

    expect(count).toBeGreaterThan(30)
  })
})
