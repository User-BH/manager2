import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { dirname, join, relative, resolve, sep } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * مرزهای وابستگیِ فرانت (مرحلهٔ ۵، فاز ۲).
 *
 * ─── 🔴 چرا این فایل وجود دارد ─────────────────────────────────────────────
 * اندازه‌گیریِ آغازِ فاز نشان داد معماری **از قبل تمیز است**: صفر حلقه،
 * صفر `shared → features`، صفر `features → app` و فقط دو یالِ بینِ ویژگی‌ها.
 *
 * ⚠️ ولی هیچ چیزی نگهش نمی‌داشت. یک `import` اشتباه کافی بود تا لایه‌بندی
 * بشکند و هیچ تستی قرمز نشود — همان الگوی تکرارشوندهٔ این پروژه: چیزی که
 * درست است ولی **محافظ ندارد**.
 *
 * ─── چرا بدونِ پکیجِ تازه ──────────────────────────────────────────────────
 * `madge` و `dependency-cruiser` و `eslint-plugin-import` هیچ‌کدام نصب
 * نیستند و قاعدهٔ صریحِ کارفرما «بی‌خودی پکیج نصب نکن» است. گرافِ import
 * از خودِ منبع ساخته می‌شود؛ ~۶۰ خط کد در برابرِ یک وابستگیِ تازه.
 *
 * ─── مرزِ صریح ─────────────────────────────────────────────────────────────
 * ⚠️ این تحلیل **ایستا و متنی** است. `import`ِ ساخته‌شده با رشتهٔ پویا را
 * نمی‌بیند. برای این پروژه کافی است چون چنین چیزی ندارد، و اولین تستِ
 * همین فایل مطمئن می‌شود گراف واقعاً خوانده شده و پوچ نیست.
 */

const ROOT = 'resources/js'
const norm = (p: string): string => p.split(sep).join('/')

function sourceFiles(): string[] {
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

/**
 * چهار شکلِ وابستگی که همه باید دیده شوند.
 *
 * `import ... from` · `export ... from` · `import()`ِ پویا ·
 * و **واردکردنِ اثرِ جانبیِ خالص**: `import '@/shared/lib/alert'`
 *
 * ─── 🔴 شکلِ چهارم را خرابکاری تحمیل کرد ───────────────────────────────────
 * نسخهٔ اول فقط سه‌تای اول را می‌گرفت. خرابکاریِ «یک حلقهٔ واقعی بساز»
 * دقیقاً از شکلِ چهارم استفاده کرد و **از تور رد شد** — دو فایل که یکدیگر
 * را وارد می‌کردند و محافظ هیچ نگفت.
 *
 * ⚠️ یعنی محافظِ حلقه از همان روزِ اول یک درِ باز داشت: هر حلقه‌ای که با
 * واردکردنِ اثرِ جانبی ساخته می‌شد نامرئی بود، و چون امروز حلقه‌ای وجود
 * ندارد، سبزبودنش هیچ چیزی ثابت نمی‌کرد.
 *
 * 🔴 درسِ تکرارشونده: محافظی که هرگز قرمز نشده، هنوز محافظ نیست.
 */
const IMPORT =
  /(?:^|\n)\s*(?:import|export)\s[^'"]*from\s*['"]([^'"]+)['"]|(?:^|\n)\s*import\s*['"]([^'"]+)['"]|import\(\s*['"]([^'"]+)['"]\s*\)/g

function resolveSpec(from: string, spec: string): string | null {
  let base: string

  if (spec.startsWith('@/')) base = join(ROOT, spec.slice(2))
  else if (spec.startsWith('.')) base = resolve(dirname(from), spec)
  else return null

  base = norm(base)

  for (const candidate of [
    base,
    `${base}.ts`,
    `${base}.tsx`,
    `${base}/index.ts`,
    `${base}/index.tsx`,
  ]) {
    if (existsSync(candidate) && statSync(candidate).isFile()) return norm(relative('.', candidate))
  }

  return null
}

function buildGraph(): Map<string, string[]> {
  const graph = new Map<string, string[]>()

  for (const file of sourceFiles()) {
    const deps = new Set<string>()

    for (const match of readFileSync(file, 'utf8').matchAll(IMPORT)) {
      const spec = match[1] ?? match[2] ?? match[3]
      if (!spec) continue

      const target = resolveSpec(file, spec)
      if (target && target !== file) deps.add(target)
    }

    graph.set(file, [...deps])
  }

  return graph
}

const graph = buildGraph()
const edges = [...graph.values()].reduce((sum, deps) => sum + deps.length, 0)

const layerOf = (path: string): string =>
  path.includes('/features/')
    ? 'features'
    : path.includes('/shared/')
      ? 'shared'
      : path.includes('/app/')
        ? 'app'
        : 'other'

const featureOf = (path: string): string | undefined => /features\/([^/]+)\//.exec(path)?.[1]
const short = (path: string): string => path.replace(`${ROOT}/`, '')

describe('مرزهای وابستگی', () => {
  /**
   * 🔴 بدونِ این، همهٔ ادعاهای پایین با گرافِ خالی هم سبز می‌شوند.
   *
   * ⚠️ اگر الگوی مسیر یا پسوندها روزی عوض شود، این تست اول قرمز می‌کند —
   * نه اینکه بقیه بی‌سروصدا پوچ شوند.
   */
  it('گراف واقعاً خوانده شده و پوچ نیست', () => {
    expect(graph.size).toBeGreaterThan(150)
    expect(edges).toBeGreaterThan(500)
  })

  /**
   * 🔴 وابستگیِ حلقوی.
   *
   * ⚠️ خطرش در جاوااسکریپت «کندی» نیست، `undefined`ِ زمانِ اجراست: یکی از
   * دو ماژول پیش از کاملِ‌شدنِ دیگری اجرا می‌شود و مقدارِ نیم‌ساخته
   * می‌گیرد. خطا هم نمی‌دهد — فقط چیزی `undefined` می‌شود.
   */
  it('هیچ وابستگیِ حلقوی وجود ندارد', () => {
    const cycles: string[][] = []
    const state = new Map<string, number>()

    const visit = (node: string, stack: string[]): void => {
      state.set(node, 1)
      stack.push(node)

      for (const dep of graph.get(node) ?? []) {
        if (state.get(dep) === 1) cycles.push([...stack.slice(stack.indexOf(dep)), dep])
        else if (!state.has(dep)) visit(dep, stack)
      }

      stack.pop()
      state.set(node, 2)
    }

    for (const file of graph.keys()) if (!state.has(file)) visit(file, [])

    expect(cycles.map((c) => c.map(short).join(' → '))).toEqual([])
  })

  /**
   * 🔴 `shared` لایهٔ پایه است و نباید به بالا نگاه کند.
   *
   * ⚠️ اگر یک ابزارِ مشترک به یک ویژگی وابسته شود، آن ویژگی عملاً وارد
   * **همهٔ** صفحه‌هایی می‌شود که آن ابزار را می‌آورند — هم از نظرِ باندل
   * و هم از نظرِ معماری.
   */
  it('shared هرگز از features چیزی وارد نمی‌کند', () => {
    const offenders: string[] = []

    for (const [file, deps] of graph) {
      if (layerOf(file) !== 'shared') continue

      for (const dep of deps) {
        if (layerOf(dep) === 'features') offenders.push(`${short(file)} → ${short(dep)}`)
      }
    }

    expect(offenders).toEqual([])
  })

  /** ⚠️ ویژگی‌ها به لایهٔ راه‌اندازیِ برنامه وابسته نمی‌شوند. */
  it('features هرگز از app چیزی وارد نمی‌کند', () => {
    const offenders: string[] = []

    for (const [file, deps] of graph) {
      if (layerOf(file) !== 'features') continue

      for (const dep of deps) {
        if (layerOf(dep) === 'app') offenders.push(`${short(file)} → ${short(dep)}`)
      }
    }

    expect(offenders).toEqual([])
  })

  /**
   * 🔴 ویژگی‌ها به **درونِ** یکدیگر دست نمی‌برند.
   *
   * ─── موردی که همین فاز بست ─────────────────────────────────────────────
   * ⚠️ `dashboard` دو بار به `features/messaging/messenger/PollCard` وصل
   * بود — یعنی importِ عمیق به درونِ ویژگیِ دیگر. `PollCard` فقط از
   * `shared/*` استفاده می‌کرد، پس به `shared/ui` منتقل شد و هر دو یال
   * حذف شدند.
   *
   * ⚠️ قاعده این نیست که ویژگی‌ها هرگز چیزی مشترک نداشته باشند؛ این است
   * که چیزِ مشترک **جایش در `shared` است**، نه در دلِ یکی از آن‌ها.
   */
  it('هیچ ویژگی‌ای به درونِ ویژگیِ دیگر وارد نمی‌شود', () => {
    const offenders: string[] = []

    for (const [file, deps] of graph) {
      if (layerOf(file) !== 'features') continue

      for (const dep of deps) {
        if (layerOf(dep) !== 'features') continue
        if (featureOf(file) === featureOf(dep)) continue

        offenders.push(`${short(file)} → ${short(dep)}`)
      }
    }

    expect(offenders).toEqual([])
  })
})
