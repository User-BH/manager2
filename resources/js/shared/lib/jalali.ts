/**
 * تقویمِ جلالی (هجریِ شمسی) — تبدیلِ دقیقِ میلادی ↔ شمسی، کبیسه و طولِ ماه (مرحلهٔ ۱۳ · فاز ۷؛ ایرادات ۱۰، بندِ ۷۳).
 *
 * الگوریتمِ شناخته‌شده‌ی «شکستگی‌های» کبیسه (Borkowski؛ همان مبنای jalaali-js و `morilog/jalali` ِ سرور)، بی بسته‌ی تازه.
 * درستی‌اش در مخزن سنجیده می‌شود: `tests/js/jalali.test.ts` هر روزِ پنجاه سال را با فهرستی که `morilog/jalali` ِ سرور
 * ساخته (`tests/fixtures/jalali-anchors.json`) و رفت‌وبرگشتِ هر روز را می‌سنجد؛ `JalaliAnchorsTest` همان فهرست را با
 * سرور. ⚠️ فقط تاریخِ تقویمی؛ ساعت و منطقه‌ی زمانی کارِ سرور است (یادآور: ساعتِ تهران، `Reminder::remindAtFor`).
 */

export interface JalaliDate {
  jy: number
  jm: number
  jd: number
}

export const JALALI_MONTHS = [
  'فروردین',
  'اردیبهشت',
  'خرداد',
  'تیر',
  'مرداد',
  'شهریور',
  'مهر',
  'آبان',
  'آذر',
  'دی',
  'بهمن',
  'اسفند',
] as const

/** روزهای هفته از شنبه — ستون‌های تقویم */
export const JALALI_WEEKDAYS = [
  'شنبه',
  'یکشنبه',
  'دوشنبه',
  'سه‌شنبه',
  'چهارشنبه',
  'پنجشنبه',
  'جمعه',
] as const

const BREAKS = [
  -61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394,
  2456, 3178,
]

const div = (a: number, b: number) => Math.trunc(a / b)
const mod = (a: number, b: number) => a - Math.trunc(a / b) * b

/** سالِ جلالی: کبیسه است؟ سالِ میلادیِ متناظر و روزِ مارسِ نوروز */
function jalCal(jy: number): { leap: number; gy: number; march: number } {
  const gy = jy + 621
  let leapJ = -14
  let jp = BREAKS[0]
  let jump = 0

  if (jy < jp || jy >= BREAKS[BREAKS.length - 1])
    throw new RangeError(`سالِ ${jy} بیرون از بازه‌ی تقویم است`)

  for (let i = 1; i < BREAKS.length; i++) {
    const jm = BREAKS[i]
    jump = jm - jp
    if (jy < jm) break
    leapJ += div(jump, 33) * 8 + div(mod(jump, 33), 4)
    jp = jm
  }

  let n = jy - jp
  leapJ += div(n, 33) * 8 + div(mod(n, 33) + 3, 4)
  if (mod(jump, 33) === 4 && jump - n === 4) leapJ += 1

  const leapG = div(gy, 4) - div((div(gy, 100) + 1) * 3, 4) - 150
  const march = 20 + leapJ - leapG

  if (jump - n < 6) n = n - jump + div(jump + 4, 33) * 33
  let leap = mod(mod(n + 1, 33) - 1, 4)
  if (leap === -1) leap = 4

  return { leap, gy, march }
}

/** شماره‌ی روزِ ژولینیِ یک تاریخِ میلادی */
function g2d(gy: number, gm: number, gd: number): number {
  const d =
    div((gy + div(gm - 8, 6) + 100100) * 1461, 4) +
    div(153 * mod(gm + 9, 12) + 2, 5) +
    gd -
    34840408
  return d - div(div(gy + 100100 + div(gm - 8, 6), 100) * 3, 4) + 752
}

function d2g(jdn: number): { gy: number; gm: number; gd: number } {
  let j = 4 * jdn + 139361631
  j = j + div(div(4 * jdn + 183187720, 146097) * 3, 4) * 4 - 3908
  const i = div(mod(j, 1461), 4) * 5 + 308
  const gd = div(mod(i, 153), 5) + 1
  const gm = mod(div(i, 153), 12) + 1
  const gy = div(j, 1461) - 100100 + div(8 - gm, 6)
  return { gy, gm, gd }
}

function j2d(jy: number, jm: number, jd: number): number {
  const r = jalCal(jy)
  return g2d(r.gy, 3, r.march) + (jm - 1) * 31 - div(jm, 7) * (jm - 7) + jd - 1
}

function d2j(jdn: number): JalaliDate {
  const gy = d2g(jdn).gy
  let jy = gy - 621
  const r = jalCal(jy)
  const jdn1f = g2d(gy, 3, r.march)
  let k = jdn - jdn1f

  if (k >= 0) {
    if (k <= 185) return { jy, jm: 1 + div(k, 31), jd: mod(k, 31) + 1 }
    k -= 186
  } else {
    jy -= 1
    k += 179
    if (r.leap === 1) k += 1
  }

  return { jy, jm: 7 + div(k, 30), jd: mod(k, 30) + 1 }
}

export function toJalali(gy: number, gm: number, gd: number): JalaliDate {
  return d2j(g2d(gy, gm, gd))
}

export function toGregorian(
  jy: number,
  jm: number,
  jd: number,
): { gy: number; gm: number; gd: number } {
  return d2g(j2d(jy, jm, jd))
}

/** کبیسه: اسفندِ ۳۰ روزه */
export function isJalaliLeap(jy: number): boolean {
  return jalCal(jy).leap === 0
}

export function jalaliMonthLength(jy: number, jm: number): number {
  if (jm <= 6) return 31
  if (jm <= 11) return 30
  return isJalaliLeap(jy) ? 30 : 29
}

/** رقمِ فارسی بی جداکننده‌ی هزارگان — برای سال و روز («۱۴۰۵»، نه «۱,۴۰۵») */
export function faDigits(value: number | string): string {
  return String(value).replace(/\d/g, (digit) => '۰۱۲۳۴۵۶۷۸۹'[Number(digit)])
}

/** «YYYY-MM-DD» ِ میلادی — همان قالبِ API */
export function isoOf(jy: number, jm: number, jd: number): string {
  const { gy, gm, gd } = toGregorian(jy, jm, jd)
  return `${gy}-${String(gm).padStart(2, '0')}-${String(gd).padStart(2, '0')}`
}

export function fromIso(iso: string): JalaliDate {
  const [gy, gm, gd] = iso.split('-').map(Number)
  return toJalali(gy, gm, gd)
}

/** ستونِ روز در تقویم (۰ = شنبه … ۶ = جمعه) */
export function jalaliWeekday(jy: number, jm: number, jd: number): number {
  // روزِ ژولینی ۰ دوشنبه است؛ شنبه = ۵
  return mod(j2d(jy, jm, jd) + 2, 7)
}

/** امروز به وقتِ تهران — تقویمِ کاربر هر جای دنیا که باشد همان روزِ مجتمع را نشان می‌دهد */
export function todayInTehran(now: Date = new Date()): JalaliDate {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Tehran',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(now)
  return fromIso(parts)
}

/** «YYYY-MM-DD HH:MM» ِ اکنون به وقتِ تهران — هم‌قالبِ لحظه‌ی یادآور تا مقایسه‌ی رشته‌ای درست باشد */
export function nowInTehran(now: Date = new Date()): string {
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat('en-CA', {
      timeZone: 'Asia/Tehran',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
    })
      .formatToParts(now)
      .map((part) => [part.type, part.value]),
  )
  return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}`
}
