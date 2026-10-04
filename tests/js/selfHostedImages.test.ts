import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * تصویرهای محتوایی از سرورِ خودمان (مرحلهٔ ۸ · فاز ۱ · بندِ ۲).
 *
 * سایت باید بی‌اینترنتِ بین‌الملل هم تصویرهایش را نشان دهد. سیاهه نشان داد
 * هیچ تصویرِ بیرونی‌ای نمانده — عکسِ ویژگی‌ها، گالری، آواتار، بنر، جلدِ بلاگ،
 * نشانِ اینماد و og:image همه در `resources/images`، `public/images` یا دیسکِ
 * خصوصی‌اند. این تست همان را **ماندگار** می‌کند: تصویرِ تازه‌ای که با آدرسِ
 * سایتِ دیگر بیاید همین‌جا قرمز می‌شود.
 *
 * مرزِ آگاهانه: **پیوند** به سایتِ دیگر آزاد است (شبکه‌های اجتماعی، صفحه‌ی
 * اینماد)؛ فقط چیزی که مرورگر برای **نمایش** می‌کشد سنجیده می‌شود. تصویرِ
 * درونِ متنِ مقاله را `BlogWriter` در سرور هم فقط از `/blog/media/` می‌پذیرد
 * (`BlogTest`).
 */

const ROOTS = [
  'resources/js',
  'resources/views',
  'resources/css',
  'resources/content',
  'database/migrations',
  'database/seeders',
  'config',
  'public/manifest.webmanifest',
]

const EXTENSIONS = /\.(tsx?|php|css|md|json|webmanifest)$/

function files(path: string): string[] {
  if (!existsSync(path)) return []
  if (statSync(path).isFile()) return [path]
  return readdirSync(path).flatMap((name) => files(join(path, name)))
}

const IMAGE_EXT = 'png|jpe?g|webp|gif|svg|avif|ico'

/** هر شکلی که مرورگر با آن تصویر می‌کشد — به‌اضافه‌ی هر آدرسِ مطلق با پسوندِ تصویر */
const PATTERNS: RegExp[] = [
  new RegExp(`https?://[^\\s"'()<>]+\\.(${IMAGE_EXT})(\\?[^\\s"'()<>]*)?`, 'gi'),
  /<img[^>]+src=["']?(?:https?:)?\/\/[^"'\s>]+/gi,
  /url\(\s*["']?(?:https?:)?\/\/[^)]+\)/gi,
  /!\[[^\]]*\]\((?:https?:)?\/\/[^)]+\)/g,
  /\b(?:poster|srcSet|srcset|og:image"?\s*content)=["']?(?:https?:)?\/\/[^"'\s>]+/gi,
]

function offenders(text: string): string[] {
  return PATTERNS.flatMap((pattern) => text.match(pattern) ?? [])
}

describe('تصویرهای محتوایی', () => {
  const sources = ROOTS.flatMap(files).filter((f) => EXTENSIONS.test(f))

  it('فایل‌های سنجیده واقعاً پیدا شده‌اند', () => {
    expect(sources.length).toBeGreaterThan(200)
    expect(sources.some((f) => f.endsWith('.md'))).toBe(true)
  })

  it('هیچ تصویری از سایتِ دیگری کشیده نمی‌شود', () => {
    const found = sources.flatMap((file) =>
      offenders(readFileSync(file, 'utf8')).map((hit) => `${file}: ${hit}`),
    )
    expect(found, found.join('\n')).toEqual([])
  })

  it('الگوها خودشان کار می‌کنند', () => {
    expect(offenders('<img src="https://cdn.example.com/a.png">')).not.toEqual([])
    expect(offenders("background: url('//images.example.com/b')")).not.toEqual([])
    expect(offenders('![x](https://example.com/c)')).not.toEqual([])
    expect(offenders('poster="https://example.com/v"')).not.toEqual([])
    // پیوند و تصویرِ خودمان آزادند
    expect(offenders('<a href="https://trustseal.enamad.ir/?id=1">')).toEqual([])
    expect(offenders('<img src="/images/enamad.png">')).toEqual([])
  })
})
