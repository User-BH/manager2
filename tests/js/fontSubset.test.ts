import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { brotliDecompressSync } from 'node:zlib'
import { describe, expect, it } from 'vitest'

/**
 * زیرمجموعه‌ی فونتِ Vazirmatn (مرحلهٔ ۷ · فاز ۳، پیگیری).
 *
 * `scripts/subset-fonts.py` حرف‌هایی را که فارسی و انگلیسیِ سامانه هرگز ندارند
 * (لاتینِ گسترده، IPA) از فونت برداشت: ‎۱۹۹ ← ‎۱۴۸KB. پیکسل‌به‌پیکسل سنجیده شد
 * که رندر عوض نشده — ولی آن سنجش یک‌باره بود.
 *
 * ⚠️ این تست همان را **ماندگار** می‌کند: هر نویسه‌ای که در متن‌های پروژه به کار
 * رفته و فونتِ **اصلی** آن را داشت، باید در زیرمجموعه هم باشد. اگر کسی فردا متنی
 * با حرفی تازه بنویسد، یا زیرمجموعه را تنگ‌تر بسازد، همین‌جا قرمز می‌شود — نه با
 * جابه‌جاییِ بی‌صدای یک حرف به فونتِ سیستم.
 *
 * فونت با `zlib`ِ خودِ Node خوانده می‌شود؛ هیچ بسته‌ی تازه‌ای لازم نیست.
 */

const WEIGHTS = ['Regular', 'Medium', 'SemiBold', 'Bold']

/** عددِ UIntBase128ِ قالبِ WOFF2. */
function base128(bytes: Buffer, at: { offset: number }): number {
  let value = 0
  for (let i = 0; i < 5; i++) {
    const byte = bytes[at.offset++]
    value = value * 128 + (byte & 0x7f)
    if ((byte & 0x80) === 0) return value
  }
  throw new Error('UIntBase128 نامعتبر')
}

const KNOWN_TAGS = [
  'cmap',
  'head',
  'hhea',
  'hmtx',
  'maxp',
  'name',
  'OS/2',
  'post',
  'cvt ',
  'fpgm',
  'glyf',
  'loca',
  'prep',
  'CFF ',
  'VORG',
  'EBDT',
  'EBLC',
  'gasp',
  'hdmx',
  'kern',
  'LTSH',
  'PCLT',
  'VDMX',
  'vhea',
  'vmtx',
  'BASE',
  'GDEF',
  'GPOS',
  'GSUB',
  'EBSC',
  'JSTF',
  'MATH',
  'CBDT',
  'CBLC',
  'COLR',
  'CPAL',
  'SVG ',
  'sbix',
  'acnt',
  'avar',
  'bdat',
  'bloc',
  'bsln',
  'cvar',
  'fdsc',
  'feat',
  'fmtx',
  'fvar',
  'gvar',
  'hsty',
  'just',
  'lcar',
  'mort',
  'morx',
  'opbd',
  'prop',
  'trak',
  'Zapf',
  'Silf',
  'Glat',
  'Gloc',
  'Feat',
  'Sill',
]

/** نقطه‌کدهای جدولِ `cmap`ِ یک فایلِ WOFF2 (قالب‌های ۴ و ۱۲). */
function codepoints(path: string): Set<number> {
  const file = readFileSync(path)
  const numTables = file.readUInt16BE(12)
  const at = { offset: 48 }
  let start = 0
  let cmap: { start: number; length: number } | null = null

  for (let i = 0; i < numTables; i++) {
    const flags = file[at.offset++]
    let tag = KNOWN_TAGS[flags & 0x3f]
    if ((flags & 0x3f) === 63) {
      tag = file.toString('latin1', at.offset, at.offset + 4)
      at.offset += 4
    }
    const version = (flags >> 6) & 3
    const origLength = base128(file, at)
    // glyf و loca با نسخه‌ی ۰ دگرگون‌اند؛ بقیه با نسخه‌ی ۰ بی‌تغییر
    const transformed = tag === 'glyf' || tag === 'loca' ? version === 0 : version !== 0
    const length = transformed ? base128(file, at) : origLength
    if (tag === 'cmap') cmap = { start, length }
    start += length
  }

  const compressedLength = file.readUInt32BE(20)
  const data = brotliDecompressSync(file.subarray(at.offset, at.offset + compressedLength))
  if (!cmap) throw new Error('cmap پیدا نشد')
  const table = data.subarray(cmap.start, cmap.start + cmap.length)

  const result = new Set<number>()
  const subtables = table.readUInt16BE(2)
  for (let i = 0; i < subtables; i++) {
    const offset = table.readUInt32BE(4 + i * 8 + 4)
    const format = table.readUInt16BE(offset)
    if (format === 4) {
      const segments = table.readUInt16BE(offset + 6) / 2
      const ends = offset + 14
      const starts = ends + segments * 2 + 2
      const deltas = starts + segments * 2
      const ranges = deltas + segments * 2
      for (let s = 0; s < segments; s++) {
        const end = table.readUInt16BE(ends + s * 2)
        const begin = table.readUInt16BE(starts + s * 2)
        const delta = table.readInt16BE(deltas + s * 2)
        const rangeOffset = table.readUInt16BE(ranges + s * 2)
        for (let cp = begin; cp <= end && cp !== 0xffff; cp++) {
          const glyph =
            rangeOffset === 0
              ? (cp + delta) & 0xffff
              : table.readUInt16BE(ranges + s * 2 + rangeOffset + (cp - begin) * 2)
          if (glyph !== 0) result.add(cp)
        }
      }
    } else if (format === 12) {
      const groups = table.readUInt32BE(offset + 12)
      for (let g = 0; g < groups; g++) {
        const base = offset + 16 + g * 12
        for (let cp = table.readUInt32BE(base); cp <= table.readUInt32BE(base + 4); cp++) {
          result.add(cp)
        }
      }
    }
  }
  return result
}

function filesUnder(dir: string, ext: RegExp): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    return statSync(path).isDirectory() ? filesUnder(path, ext) : ext.test(name) ? [path] : []
  })
}

/** هر نویسه‌ای که در متن‌های دیدنیِ پروژه آمده. */
function usedCharacters(): Set<number> {
  const sources = [
    ...filesUnder('resources/js', /\.tsx?$/),
    ...filesUnder('resources/views', /\.php$/),
    ...filesUnder('resources/content', /\.json$/),
    'app/Services/Support/KnowledgeBase.php',
  ]
  const used = new Set<number>()
  for (const path of sources) {
    for (const char of readFileSync(path, 'utf8')) {
      const cp = char.codePointAt(0)!
      // نویسه‌ی کنترلی (CR در فایل‌های CRLF) هرگز رسم نمی‌شود
      if (cp >= 0x20) used.add(cp)
    }
  }
  return used
}

describe('زیرمجموعه‌ی فونت', () => {
  const used = usedCharacters()

  for (const weight of WEIGHTS) {
    it(`Vazirmatn-${weight}: هیچ نویسه‌ی به‌کاررفته‌ای که فونتِ اصلی داشت گم نشده`, () => {
      const original = codepoints(`resources/fonts/source/Vazirmatn-${weight}.woff2`)
      const subset = codepoints(`resources/fonts/Vazirmatn-${weight}.woff2`)

      const lost = [...used]
        .filter((cp) => original.has(cp) && !subset.has(cp))
        .map((cp) => `U+${cp.toString(16).toUpperCase().padStart(4, '0')}`)

      expect(lost).toEqual([])
    })
  }

  /*
   * ⚠️ متنِ کاربر (پیام، بلاگ، نام) هر حرفِ فارسی را می‌تواند داشته باشد، نه
   * فقط آن‌هایی که امروز در کد آمده‌اند. پس کلِ بلوک‌های فارسی و لاتینِ پایه
   * — هرچه فونتِ اصلی از آن‌ها داشت — باید مانده باشد.
   */
  it('کلِ فارسی و عربی، ASCII و Latin-1ِ فونتِ اصلی مانده است', () => {
    const original = codepoints('resources/fonts/source/Vazirmatn-Regular.woff2')
    const subset = codepoints('resources/fonts/Vazirmatn-Regular.woff2')
    const keep = (cp: number) =>
      (cp >= 0x20 && cp <= 0xff) ||
      (cp >= 0x0600 && cp <= 0x06ff) ||
      (cp >= 0x200c && cp <= 0x200f) ||
      (cp >= 0xfb50 && cp <= 0xfeff)

    const lost = [...original].filter((cp) => keep(cp) && !subset.has(cp))

    expect(lost).toEqual([])
    expect(subset.size).toBeLessThan(original.size)
  })

  it('خواننده‌ی cmap خودش درست کار می‌کند', () => {
    // ⚠️ بی‌این، خطای خواندن هر دو مجموعه را تهی می‌کرد و تست‌های بالا پوچ سبز می‌شدند
    const subset = codepoints('resources/fonts/Vazirmatn-Regular.woff2')

    expect(subset.has('س'.codePointAt(0)!)).toBe(true)
    expect(subset.has(0x200c)).toBe(true)
    expect(subset.has('A'.codePointAt(0)!)).toBe(true)
    expect(subset.size).toBeGreaterThan(500)
  })
})
