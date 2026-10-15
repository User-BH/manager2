import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * ترتیبِ لایه‌های داشبورد — «چند نکته»، بندِ ۵.
 *
 * محتوای صفحه ≤ z-30 · کارتِ نصب (پایینِ صفحه) z-40 · سربرگ و باکس‌هایش z-45 · پوشش‌های مسدودکننده (`fixed`) z-50.
 * رفتارِ واقعی را `tests/e2e/overlay-layers.spec.ts` در مرورگر می‌سنجد؛ این‌جا جلوی برگشتنِ علت گرفته می‌شود: عنصرِ
 * جای‌گرفته‌ای در صفحه‌های داشبورد که به لایه‌ی سربرگ برسد، دوباره روی باکسِ اعلان می‌نشیند.
 */

const ROOT = join(__dirname, '../../resources/js')

function files(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    return statSync(path).isDirectory() ? files(path) : path.endsWith('.tsx') ? [path] : []
  })
}

describe('لایه‌های داشبورد', () => {
  it('سربرگ بالای محتوای صفحه است — z-45، نه z-20 که با نمایشگرِ چسبانِ صفحه برابر می‌شد', () => {
    const header = readFileSync(join(ROOT, 'shared/layout/Header.tsx'), 'utf8')
    expect(header).toMatch(/<header\s+className="sticky top-0 z-45 /)
  })

  it('هیچ عنصرِ جای‌گرفته‌ای در صفحه‌های داشبورد به لایه‌ی سربرگ نمی‌رسد', () => {
    /*
     * صفحه‌های عمومی (`features/landing`) داشبورد نیستند؛ `Tooltip` فقط روی هاورِ ماشه‌ی خودش دیده می‌شود و ماشه زیرِ
     * باکسِ باز هاورپذیر نیست. پوشش‌های `fixed` (Modal، کشو) عمداً بالای سربرگ‌اند.
     */
    const allowed = new Set(['shared/ui/Tooltip.tsx'])
    const offenders: string[] = []

    for (const path of files(ROOT)) {
      const rel = relative(ROOT, path).replace(/\\/g, '/')
      if (
        rel.startsWith('features/landing/') ||
        rel.startsWith('shared/layout/') ||
        allowed.has(rel)
      )
        continue

      for (const literal of readFileSync(path, 'utf8').match(
        /(['"`])[^'"`\n]*\bz-\d+\b[^'"`\n]*\1/g,
      ) ?? []) {
        const level = Number(/\bz-(\d+)\b/.exec(literal)?.[1])
        if (level >= 45 && !/\bfixed\b/.test(literal)) offenders.push(`${rel}: ${literal}`)
      }
    }

    expect(offenders).toEqual([])
  })
})
