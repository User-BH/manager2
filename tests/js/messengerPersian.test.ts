import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * متنِ قابلِ‌دیدنِ پیام‌رسان فارسی است — «چند نکته»، بندِ ۴.
 *
 * هر راهی که متن از آن به کاربر می‌رسد سنجیده می‌شود: متنِ JSX، برچسب‌های دسترس‌پذیری و راهنما (`aria-label`، `title`،
 * `placeholder`، `alt`، `label`) و پیام‌های خطا و تأیید (`toast*`، `alert*`، `confirmAction`، `onNotice`، `setError`).
 * نامِ فنیِ API و شناسه‌ها عمداً دست نخوردند — این‌جا فقط آنچه دیده می‌شود. پیامِ خطای سرور را `PersianValidationTest`
 * می‌سنجد.
 */

const ROOT = join(__dirname, '../../resources/js')
const SOURCES = ['features/messaging', 'shared/ui/PollCard.tsx']

function files(path: string): string[] {
  const full = join(ROOT, path)
  if (!statSync(full).isDirectory()) return [full]
  return readdirSync(full).flatMap((name) => files(join(path, name)))
}

/** واژه‌ی لاتینِ دو حرفی به بالا — نه رقم و نه نماد */
const LATIN = /[A-Za-z]{2,}/

function visibleTexts(source: string): string[] {
  const code = source
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/\{\/\*[\s\S]*?\*\/\}/g, '')
    .replace(/\/\/[^\n]*/g, '')
  const found: string[] = []

  // متنِ JSX: پس از `>` و پیش از یک تگِ بسته
  for (const m of code.matchAll(/>([^<>{}]*?)(?=<\/)/g)) found.push(m[1].trim())
  // ویژگی‌های دیدنی
  for (const m of code.matchAll(/\b(?:aria-label|title|placeholder|alt|label)=(["'])(.*?)\1/g))
    found.push(m[2])
  // همان ویژگی‌ها با عبارت (`label={isPinned ? '…' : '…'}`) و ویژگیِ `label:` در داده‌ی منوها
  for (const m of code.matchAll(/\b(?:aria-label|title|placeholder|alt|label)=\{([^{}]*)\}/g))
    // فقط رشته‌ای که خودِ نتیجه است (پس از `?`، `:`، `||`، `??` یا کلِ عبارت) — نه مقدارِ مقایسه مثلِ `kind === 'video_message'`
    for (const literal of m[1].matchAll(/(?:^|[?:]|\|\||\?\?)\s*(['"`])(.*?)\1/g))
      found.push(literal[2])
  for (const m of code.matchAll(/\b(?:label|title|hint|description):\s*(['"`])(.*?)\1/g))
    found.push(m[2])
  // پیام‌های خطا، تأیید و راهنما
  for (const m of code.matchAll(
    /\b(?:toastError|toastSuccess|alertError|alertInfo|onNotice|setError|confirmAction\(\{\s*title:|text:|confirmLabel:)\s*\(?\s*(['"`])(.*?)\1/g,
  ))
    found.push(m[2])

  return found.filter((text) => text !== '')
}

describe('فارسی‌سازیِ پیام‌رسان', () => {
  it('هیچ متنِ قابلِ دیدنی واژه‌ی لاتین ندارد (هدایت، پاسخ، سنجاق، ویرایش، حذف، کپی، دانلود، واکنش، نظرسنجی، …)', () => {
    const offenders: string[] = []
    let scanned = 0

    for (const file of SOURCES.flatMap(files).filter((f) => /\.tsx?$/.test(f))) {
      for (const text of visibleTexts(readFileSync(file, 'utf8'))) {
        scanned++
        if (LATIN.test(text.replace(/\$\{[^}]*\}/g, ''))) {
          offenders.push(`${relative(ROOT, file)}: «${text}»`)
        }
      }
    }

    expect(scanned, 'متنی خوانده نشد؛ محافظ پوچ می‌شود.').toBeGreaterThan(80)
    expect(offenders).toEqual([])
  })
})
