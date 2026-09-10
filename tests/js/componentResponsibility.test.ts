import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, sep } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * تفکیکِ مسئولیتِ کامپوننت‌ها (مرحلهٔ ۵، فاز ۲ — بندهای ۹ و ۱۲).
 *
 * ─── 🔴 چرا اینجا بازآراییِ بزرگی انجام نشد ────────────────────────────────
 * بندِ ۱۲ نگرانِ کامپوننتی است که **هم‌زمان** API و منطقِ کسب‌وکار و حالت و
 * اعتبارسنجی و رابط و انیمیشن و قالب‌بندی را انجام بدهد.
 *
 * ⚠️ اندازه‌گیری شد. چنین کامپوننتی **وجود ندارد**:
 *
 *     ۵ دغدغه:  ۰ فایل
 *     ۴ دغدغه:  ۰ فایل
 *     ۳ دغدغه:  ۹ فایل
 *     بیشترین useState: ۹ (پخش‌کنندهٔ ویدیو) و بعدش ۵
 *     بیشترین useEffect: ۵ (همان فایل) و بعدش ۳
 *
 * 🔴 چند صفحه بلندند (تا ۶۹۲ خط) ولی بلندی‌شان از JSX است، نه از انباشتِ
 * منطق. شکستنِ آن‌ها بدونِ نقصِ مشخص، ریسکِ رگرسیون روی کدی است که تازه
 * سخت‌سازی شده — و قاعدهٔ «جلوگیری از Overengineering» را می‌شکند.
 *
 * ─── پس این فایل چه کار می‌کند ─────────────────────────────────────────────
 * ⚠️ به‌جای بازآراییِ امروز، **رشد** را می‌بندد: معیارِ خودِ بند را
 * مکانیزه می‌کند تا کامپوننتِ بعدی نتواند بی‌سروصدا به آن‌جا برسد.
 */

const ROOT = 'resources/js'
const norm = (p: string): string => p.split(sep).join('/')

function components(): string[] {
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

/** ⚠️ کامنت‌ها پاک می‌شوند، وگرنه توضیحِ بالای کد به‌جای خودِ کد شمرده می‌شود. */
function codeOf(path: string): string {
  return readFileSync(path, 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/^\s*\/\/.*$/gm, '')
}

interface Profile {
  file: string
  concerns: number
  states: number
  effects: number
}

function profile(path: string): Profile {
  const code = codeOf(path)

  const has = {
    api: /\bapi[<(]|useQuery\(|useMutation\(|fetch\(/.test(code),
    schema: /z\.object\(|zodResolver|\.safeParse\(/.test(code),
    anim: /motion\.|AnimatePresence/.test(code),
    format: /toLocaleString|Intl\.NumberFormat|toFixed\(/.test(code),
  }

  const states = (code.match(/useState\(/g) ?? []).length
  const effects = (code.match(/useEffect\(/g) ?? []).length

  return {
    file: path.replace(`${ROOT}/`, ''),
    concerns: [has.api, has.schema, has.anim, has.format, states > 4].filter(Boolean).length,
    states,
    effects,
  }
}

/**
 * کامپوننت‌هایی که ذاتاً حالتِ زیاد دارند — با دلیل.
 *
 * ⚠️ همان الگوی معافیتِ تصویر در `PerformanceBaselineTest`: معافیت باید
 * **نوشته** شود، وگرنه فایلِ سنگینِ بعدی هم بی‌صدا معاف می‌شود.
 */
const HEAVY_BY_NATURE: Record<string, string> = {
  'features/landing/demo/VideoPlayer.tsx':
    'پخش‌کنندهٔ رسانه: پخش، صدا، پیشرفت، مدت، تمام‌صفحه و اسلاید هرکدام حالتِ مستقل‌اند. ⚠️ ضمناً خودِ ویدیوی دمو هنوز تحویل نشده و این کامپوننت منتظرِ آن است.',
}

const profiles = components().map(profile)

describe('تفکیکِ مسئولیت', () => {
  /** 🔴 بدونِ این، همهٔ ادعاهای پایین با فهرستِ خالی هم سبز می‌شوند. */
  it('کامپوننت‌ها واقعاً خوانده شده‌اند', () => {
    expect(profiles.length).toBeGreaterThan(80)
  })

  /**
   * 🔴 هیچ کامپوننتی هر پنج دغدغه را با هم ندارد.
   *
   * ⚠️ این دقیقاً معیارِ بندِ ۱۲ است، نه شمارشِ خط. امروز صفر است و باید
   * صفر بماند.
   */
  it('هیچ کامپوننتی همهٔ دغدغه‌ها را با هم ندارد', () => {
    const overloaded = profiles
      .filter((p) => p.concerns >= 5)
      .map((p) => `${p.file} (${p.concerns})`)

    expect(overloaded).toEqual([])
  })

  /**
   * ⚠️ و حتی چهار دغدغه هم امروز وجود ندارد — بیشینه سه است.
   *
   * 🔴 سقف عمداً روی همین گذاشته شد، نه یک عددِ سخاوتمندانه: وقتی
   * وضعیتِ امروز صفر است، هر سقفِ بالاتر یعنی اجازهٔ بی‌سروصدای بدتر
   * شدن.
   *
   * ⚠️ نُه فایلِ سه‌دغدغه‌ای بیشترشان API + انیمیشن + قالب‌بندیِ مبلغ
   * دارند. قالب‌بندیِ مرکزیِ مبلغ کارِ **فاز ۵** است (بندِ ۴۳) و همان‌جا
   * این عدد را پایین می‌آورد.
   */
  it('هیچ کامپوننتی حتی چهار دغدغه را هم با هم ندارد', () => {
    const heavy = profiles.filter((p) => p.concerns >= 4).map((p) => `${p.file} (${p.concerns})`)

    expect(heavy).toEqual([])
  })

  /**
   * 🔴 حالت در یک کامپوننت پخش نمی‌شود.
   *
   * ⚠️ بندِ ۹ می‌پرسد «آیا State در جای مناسبی است؟». بیرون از معافیت،
   * بیشترین مقدارِ امروز **۵** است؛ کامپوننتی با ده `useState` معمولاً
   * یعنی حالت باید به یک هوک یا `useReducer` منتقل شود.
   */
  it('هیچ کامپوننتی حالتِ پراکنده ندارد', () => {
    const sprawling = profiles
      .filter((p) => !(p.file in HEAVY_BY_NATURE))
      .filter((p) => p.states > 6)
      .map((p) => `${p.file} (${p.states} useState)`)

    expect(sprawling).toEqual([])
  })

  /**
   * ⚠️ و اثرها هم همین‌طور.
   *
   * 🔴 هر `useEffect` یک مسیرِ همگام‌سازیِ دستی است؛ انباشتشان همان جایی
   * است که رندرهای اضافی و حلقه‌های وابستگی متولد می‌شوند.
   */
  it('هیچ کامپوننتی انبوهِ useEffect ندارد', () => {
    const heavy = profiles
      .filter((p) => !(p.file in HEAVY_BY_NATURE))
      .filter((p) => p.effects > 4)
      .map((p) => `${p.file} (${p.effects} useEffect)`)

    expect(heavy).toEqual([])
  })

  /**
   * ⚠️ و معافیت‌ها کهنه نمی‌مانند.
   *
   * 🔴 معافیتی که فایلش دیگر وجود ندارد، یعنی سیاهه دارد به فهرستی
   * تبدیل می‌شود که کسی نمی‌خواندش — همان درسِ معافیتِ تصویر در
   * `PerformanceBaselineTest`.
   */
  it('هر معافیت هنوز به فایلی واقعی اشاره می‌کند', () => {
    const known = profiles.map((p) => p.file)
    const stale = Object.keys(HEAVY_BY_NATURE).filter((f) => !known.includes(f))

    expect(stale).toEqual([])
  })
})
