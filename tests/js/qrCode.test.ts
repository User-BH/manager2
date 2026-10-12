import { describe, expect, it } from 'vitest'
import { encodeQr, MAX_VERSION } from '@/shared/lib/qrCode'
import reference from './fixtures/qrOpencv.json'

/**
 * رمزگذارِ QR (مرحلهٔ ۱۰ · فاز ۵) در برابرِ یک رمزگذارِ **مستقل**.
 *
 * `fixtures/qrOpencv.json` خروجیِ `cv2.QRCodeEncoder` ِ OpenCV 4.13 است (سطحِ M، بایت‌مود) برای یک متن در هر
 * نسخه‌ی ۱ تا ۱۰ — یعنی هم یک‌بلوکی، هم چندبلوکی با بلوک‌های کوتاه و بلند، هم اطلاعاتِ نسخه (۷ به بالا).
 * با همان ماسکی که OpenCV برگزید، ماتریسِ ما ماژول‌به‌ماژول همان است؛ تنها استثنا بیت‌های «باقی‌مانده»اند
 * که OpenCV ماسک نمی‌کند و استاندارد پیش از ماسک صفر می‌گذارد (خواننده‌ها نادیده‌شان می‌گیرند).
 *
 * خواندنِ QR ِ ساخته‌شده با رمزگشای OpenCV جدا و محلی سنجیده شد (`docs/MEMBERSHIP.md`)؛ CI پایتون ندارد،
 * پس همین مرجع‌ها نگهبانِ دائمی‌اند.
 */

interface Reference {
  text: string
  version: number
  mask: number
  rows: string[]
  remainder: number[][]
}

const codes = (reference as { codes: Reference[] }).codes

describe('رمزگذارِ QR', () => {
  it('مرجع‌ها همه‌ی نسخه‌های ۱ تا ۱۰ را می‌پوشانند', () => {
    expect(codes.map((c) => c.version)).toEqual([1, 2, 3, 4, 5, 6, 7, 8, 9, 10])
    expect(MAX_VERSION).toBe(10)
  })

  it.each(codes.map((c) => [c.version, c] as const))(
    'نسخه‌ی %i ماژول‌به‌ماژول همان OpenCV است',
    (_version, code) => {
      const ours = encodeQr(code.text, code.mask)

      expect(ours.version).toBe(code.version)
      expect(ours.size).toBe(code.rows.length)

      // استثنا فقط بیتِ باقی‌مانده، و فقط در دو ستونِ آخرِ زیگزاگ (پایین‌چپ)
      expect(code.remainder.length).toBeLessThanOrEqual(1)
      const skip = new Set(
        code.remainder.map(([y, x]) => {
          expect(x).toBeLessThanOrEqual(1)
          return `${y}:${x}`
        }),
      )

      const mismatches: string[] = []
      code.rows.forEach((row, y) =>
        [...row].forEach((cell, x) => {
          if (skip.has(`${y}:${x}`)) return
          if ((cell === '1') !== ours.modules[y][x]) mismatches.push(`${y}:${x}`)
        }),
      )
      expect(mismatches).toEqual([])
    },
  )

  it('ماسکِ خودکار همان ماتریسِ ماسکِ اجباری است و قالب را درست می‌نویسد', () => {
    for (const code of codes) {
      const auto = encodeQr(code.text)
      expect(auto.mask).toBeGreaterThanOrEqual(0)
      expect(auto.mask).toBeLessThan(8)
      expect(auto.modules).toEqual(encodeQr(code.text, auto.mask).modules)
    }
  })

  it('کوچک‌ترین نسخه‌ای که جا می‌شود؛ بلندتر از نسخه‌ی ۱۰ رد می‌شود', () => {
    expect(encodeQr('x'.repeat(14)).version).toBe(1)
    expect(encodeQr('x'.repeat(15)).version).toBe(2)
    expect(encodeQr('x'.repeat(62)).version).toBe(4)
    expect(encodeQr('x'.repeat(213)).version).toBe(10)
    expect(() => encodeQr('x'.repeat(214))).toThrow(RangeError)
  })

  it('اندازه، سه یاب، خطِ زمان‌بندی و ماژولِ همیشه‌تیره', () => {
    const { size, modules } = encodeQr('https://sakena.ir/join#AAAAAAAAAAAAAAAAAAAAAA')
    expect(size).toBe(33)

    // گوشه‌ی بیرونیِ هر یاب تیره، حلقه‌ی دوم روشن، مرکز تیره
    for (const [x, y] of [
      [0, 0],
      [size - 7, 0],
      [0, size - 7],
    ]) {
      expect(modules[y][x]).toBe(true)
      expect(modules[y + 1][x + 1]).toBe(false)
      expect(modules[y + 3][x + 3]).toBe(true)
    }
    for (let i = 8; i < size - 8; i++) {
      expect(modules[6][i]).toBe(i % 2 === 0)
      expect(modules[i][6]).toBe(i % 2 === 0)
    }
    expect(modules[size - 8][8]).toBe(true)
  })

  it('متنِ فارسی به‌صورتِ UTF-8 شمرده می‌شود، نه با طولِ رشته', () => {
    // ۷ نویسه‌ی فارسی = ۱۴ بایت (جا در نسخه‌ی ۱)؛ ۸ نویسه = ۱۶ بایت (نسخه‌ی ۲)
    expect(encodeQr('سلامساکن').version).toBe(2)
    expect(encodeQr('سلامساک').version).toBe(1)
  })
})
