/**
 * رمزگذارِ QR (ISO/IEC 18004) — بایت‌مود، سطحِ تصحیحِ خطای M، نسخه‌های ۱ تا ۱۰ (مرحلهٔ ۱۰ · فاز ۵).
 *
 * چرا خودمان و نه یک بسته: «بی‌خودی بسته نصب نکن»، و QR ِ ورودِ عمومیِ مجتمع فقط یک نشانیِ کوتاه است
 * (حدودِ ۶۰ بایت ← نسخه‌ی ۴). الگوریتم همان روشِ مرجع است: داده ← کدواژه‌ها ← Reed–Solomon و درهم‌آمیختنِ
 * بلوک‌ها ← الگوهای ثابت ← چیدنِ زیگزاگ ← انتخابِ ماسک با کم‌ترین جریمه.
 *
 * درستی‌اش در `tests/js/qrCode.test.ts` با ماتریس‌های **رمزگذارِ مستقلِ OpenCV** سنجیده می‌شود، و خواندنِ
 * QR ِ ساخته‌شده با رمزگشای OpenCV در `docs/MEMBERSHIP.md` ثبت است.
 */

/** کدواژه‌های تصحیحِ خطا در هر بلوک و شمارِ بلوک‌ها برای سطحِ M؛ اندیس = نسخه */
const ECC_PER_BLOCK = [0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26]
const BLOCKS = [0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5]

export const MAX_VERSION = 10

export interface QrMatrix {
  version: number
  mask: number
  size: number
  /** `modules[y][x]` — true یعنی تیره */
  modules: boolean[][]
}

export function encodeQr(text: string, forcedMask?: number): QrMatrix {
  const bytes = Array.from(new TextEncoder().encode(text))

  let version = 1
  while (4 + countBits(version) + bytes.length * 8 > dataCodewords(version) * 8) {
    if (++version > MAX_VERSION) throw new RangeError('متن برای QR بلند است.')
  }

  const capacity = dataCodewords(version) * 8
  const bits: number[] = []
  const push = (value: number, length: number): void => {
    for (let i = length - 1; i >= 0; i--) bits.push((value >>> i) & 1)
  }

  push(0b0100, 4)
  push(bytes.length, countBits(version))
  bytes.forEach((b) => push(b, 8))
  push(0, Math.min(4, capacity - bits.length))
  push(0, (8 - (bits.length % 8)) % 8)
  for (let pad = 0xec; bits.length < capacity; pad ^= 0xec ^ 0x11) push(pad, 8)

  const data: number[] = []
  for (let i = 0; i < bits.length; i += 8) {
    data.push(bits.slice(i, i + 8).reduce((acc, bit) => (acc << 1) | bit, 0))
  }

  return new Grid(version).build(interleave(data, version), forcedMask)
}

function countBits(version: number): number {
  return version <= 9 ? 8 : 16
}

function rawModules(version: number): number {
  let result = (16 * version + 128) * version + 64
  if (version >= 2) {
    const align = Math.floor(version / 7) + 2
    result -= (25 * align - 10) * align - 55
    if (version >= 7) result -= 36
  }
  return result
}

function dataCodewords(version: number): number {
  return Math.floor(rawModules(version) / 8) - ECC_PER_BLOCK[version] * BLOCKS[version]
}

/* ── Reed–Solomon روی GF(2^8) با چندجمله‌ایِ 0x11D ─────────────────────────── */

function gfMultiply(x: number, y: number): number {
  let z = 0
  for (let i = 7; i >= 0; i--) {
    z = (z << 1) ^ ((z >>> 7) * 0x11d)
    z ^= ((y >>> i) & 1) * x
  }
  return z
}

function rsDivisor(degree: number): number[] {
  const result = new Array<number>(degree).fill(0)
  result[degree - 1] = 1
  let root = 1
  for (let i = 0; i < degree; i++) {
    for (let j = 0; j < degree; j++) {
      result[j] = gfMultiply(result[j], root)
      if (j + 1 < degree) result[j] ^= result[j + 1]
    }
    root = gfMultiply(root, 0x02)
  }
  return result
}

function rsRemainder(data: number[], divisor: number[]): number[] {
  const result = divisor.map(() => 0)
  for (const b of data) {
    const factor = b ^ (result.shift() as number)
    result.push(0)
    divisor.forEach((coefficient, i) => {
      result[i] ^= gfMultiply(coefficient, factor)
    })
  }
  return result
}

/** بلوک‌ها با تصحیحِ خطایشان، درهم‌آمیخته: بلوک‌های کوتاه اول، بلندها یک کدواژه‌ی داده‌ی بیشتر دارند. */
function interleave(data: number[], version: number): number[] {
  const blocks = BLOCKS[version]
  const eccLength = ECC_PER_BLOCK[version]
  const raw = Math.floor(rawModules(version) / 8)
  const shortBlocks = blocks - (raw % blocks)
  const shortLength = Math.floor(raw / blocks)
  const divisor = rsDivisor(eccLength)

  const all: number[][] = []
  for (let i = 0, k = 0; i < blocks; i++) {
    const block = data.slice(k, (k += shortLength - eccLength + (i < shortBlocks ? 0 : 1)))
    const ecc = rsRemainder(block, divisor)
    if (i < shortBlocks) block.push(0)
    all.push([...block, ...ecc])
  }

  const result: number[] = []
  for (let i = 0; i < all[0].length; i++) {
    all.forEach((block, j) => {
      // جای خالیِ بلوکِ کوتاه پر نمی‌شود
      if (i !== shortLength - eccLength || j >= shortBlocks) result.push(block[i])
    })
  }
  return result
}

/* ── ماتریس ───────────────────────────────────────────────────────────────── */

class Grid {
  readonly size: number
  private readonly modules: boolean[][]
  private readonly reserved: boolean[][]

  constructor(private readonly version: number) {
    this.size = version * 4 + 17
    this.modules = Array.from({ length: this.size }, () =>
      new Array<boolean>(this.size).fill(false),
    )
    this.reserved = Array.from({ length: this.size }, () =>
      new Array<boolean>(this.size).fill(false),
    )
  }

  build(codewords: number[], forcedMask?: number): QrMatrix {
    this.drawFunctionPatterns()
    this.drawCodewords(codewords)

    let mask = forcedMask ?? 0
    if (forcedMask === undefined) {
      let best = Infinity
      for (let candidate = 0; candidate < 8; candidate++) {
        this.applyMask(candidate)
        this.drawFormat(candidate)
        const penalty = this.penalty()
        if (penalty < best) {
          best = penalty
          mask = candidate
        }
        this.applyMask(candidate) // XOR دوباره = برگرداندن
      }
    }

    this.applyMask(mask)
    this.drawFormat(mask)

    return { version: this.version, mask, size: this.size, modules: this.modules }
  }

  private set(x: number, y: number, dark: boolean): void {
    this.modules[y][x] = dark
    this.reserved[y][x] = true
  }

  private drawFunctionPatterns(): void {
    const size = this.size

    for (let i = 0; i < size; i++) {
      this.set(6, i, i % 2 === 0)
      this.set(i, 6, i % 2 === 0)
    }

    for (const [x, y] of [
      [3, 3],
      [size - 4, 3],
      [3, size - 4],
    ]) {
      for (let dy = -4; dy <= 4; dy++) {
        for (let dx = -4; dx <= 4; dx++) {
          const distance = Math.max(Math.abs(dx), Math.abs(dy))
          if (x + dx >= 0 && x + dx < size && y + dy >= 0 && y + dy < size) {
            this.set(x + dx, y + dy, distance !== 2 && distance !== 4)
          }
        }
      }
    }

    const positions = this.alignmentPositions()
    const last = positions.length - 1
    positions.forEach((py, i) =>
      positions.forEach((px, j) => {
        // سه گوشه‌ی یاب الگوی هم‌ترازی ندارند
        if ((i === 0 && j === 0) || (i === 0 && j === last) || (i === last && j === 0)) return
        for (let dy = -2; dy <= 2; dy++) {
          for (let dx = -2; dx <= 2; dx++) {
            this.set(px + dx, py + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1)
          }
        }
      }),
    )

    // جای بیت‌های قالب (پس از انتخابِ ماسک نوشته می‌شوند) و اطلاعاتِ نسخه
    this.drawFormat(0)
    if (this.version >= 7) {
      let remainder = this.version
      for (let i = 0; i < 12; i++) remainder = (remainder << 1) ^ ((remainder >>> 11) * 0x1f25)
      const bits = (this.version << 12) | remainder
      for (let i = 0; i < 18; i++) {
        const dark = ((bits >>> i) & 1) === 1
        const a = size - 11 + (i % 3)
        const b = Math.floor(i / 3)
        this.set(a, b, dark)
        this.set(b, a, dark)
      }
    }
  }

  private alignmentPositions(): number[] {
    if (this.version === 1) return []
    const count = Math.floor(this.version / 7) + 2
    const step = Math.ceil((this.version * 4 + 4) / (count * 2 - 2)) * 2
    const result = [6]
    for (let position = this.size - 7; result.length < count; position -= step) {
      result.splice(1, 0, position)
    }
    return result
  }

  /** سطحِ M (بیت‌های 00) و ماسک، با BCH(15,5) و XOR ِ 0x5412 */
  private drawFormat(mask: number): void {
    const data = mask
    let remainder = data
    for (let i = 0; i < 10; i++) remainder = (remainder << 1) ^ ((remainder >>> 9) * 0x537)
    const bits = ((data << 10) | remainder) ^ 0x5412
    const bit = (i: number): boolean => ((bits >>> i) & 1) === 1
    const size = this.size

    for (let i = 0; i <= 5; i++) this.set(8, i, bit(i))
    this.set(8, 7, bit(6))
    this.set(8, 8, bit(7))
    this.set(7, 8, bit(8))
    for (let i = 9; i < 15; i++) this.set(14 - i, 8, bit(i))

    for (let i = 0; i < 8; i++) this.set(size - 1 - i, 8, bit(i))
    for (let i = 8; i < 15; i++) this.set(8, size - 15 + i, bit(i))
    this.set(8, size - 8, true) // ماژولِ همیشه‌تیره
  }

  private drawCodewords(codewords: number[]): void {
    const size = this.size
    let i = 0
    for (let right = size - 1; right >= 1; right -= 2) {
      if (right === 6) right = 5 // ستونِ زمان‌بندی
      for (let vertical = 0; vertical < size; vertical++) {
        for (let j = 0; j < 2; j++) {
          const x = right - j
          const upward = ((right + 1) & 2) === 0
          const y = upward ? size - 1 - vertical : vertical
          if (!this.reserved[y][x] && i < codewords.length * 8) {
            this.modules[y][x] = ((codewords[i >>> 3] >>> (7 - (i & 7))) & 1) === 1
            i++
          }
        }
      }
    }
  }

  private applyMask(mask: number): void {
    for (let y = 0; y < this.size; y++) {
      for (let x = 0; x < this.size; x++) {
        if (this.reserved[y][x]) continue
        let invert: boolean
        switch (mask) {
          case 0:
            invert = (x + y) % 2 === 0
            break
          case 1:
            invert = y % 2 === 0
            break
          case 2:
            invert = x % 3 === 0
            break
          case 3:
            invert = (x + y) % 3 === 0
            break
          case 4:
            invert = (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0
            break
          case 5:
            invert = ((x * y) % 2) + ((x * y) % 3) === 0
            break
          case 6:
            invert = (((x * y) % 2) + ((x * y) % 3)) % 2 === 0
            break
          default:
            invert = (((x + y) % 2) + ((x * y) % 3)) % 2 === 0
        }
        if (invert) this.modules[y][x] = !this.modules[y][x]
      }
    }
  }

  /* ── جریمه‌ی ماسک: چهار قاعده‌ی استاندارد ──────────────────────────────── */

  private penalty(): number {
    const size = this.size
    const at = (x: number, y: number, transposed: boolean): boolean =>
      transposed ? this.modules[x][y] : this.modules[y][x]
    let result = 0

    for (const transposed of [false, true]) {
      for (let y = 0; y < size; y++) {
        let color = false
        let run = 0
        const history = [0, 0, 0, 0, 0, 0, 0]
        for (let x = 0; x < size; x++) {
          if (at(x, y, transposed) === color) {
            run++
            if (run === 5) result += 3
            else if (run > 5) result++
          } else {
            this.addHistory(run, history)
            if (!color) result += this.finderLike(history) * 40
            color = at(x, y, transposed)
            run = 1
          }
        }
        if (color) {
          this.addHistory(run, history)
          run = 0
        }
        this.addHistory(run + size, history)
        result += this.finderLike(history) * 40
      }
    }

    for (let y = 0; y < size - 1; y++) {
      for (let x = 0; x < size - 1; x++) {
        const c = this.modules[y][x]
        if (
          c === this.modules[y][x + 1] &&
          c === this.modules[y + 1][x] &&
          c === this.modules[y + 1][x + 1]
        ) {
          result += 3
        }
      }
    }

    const dark = this.modules.reduce((sum, row) => sum + row.filter(Boolean).length, 0)
    const total = size * size
    result += (Math.ceil(Math.abs(dark * 20 - total * 10) / total) - 1) * 10

    return result
  }

  private addHistory(run: number, history: number[]): void {
    // حاشیه‌ی روشنِ بیرونی به اولین دوره افزوده می‌شود
    if (history[0] === 0) run += this.size
    history.pop()
    history.unshift(run)
  }

  private finderLike(h: number[]): number {
    const n = h[1]
    const core = n > 0 && h[2] === n && h[3] === n * 3 && h[4] === n && h[5] === n
    return (
      (core && h[0] >= n * 4 && h[6] >= n ? 1 : 0) + (core && h[6] >= n * 4 && h[0] >= n ? 1 : 0)
    )
  }
}
