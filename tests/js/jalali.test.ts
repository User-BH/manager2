import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import {
  fromIso,
  isJalaliLeap,
  isoOf,
  jalaliMonthLength,
  jalaliWeekday,
  toGregorian,
  toJalali,
  todayInTehran,
} from '@/shared/lib/jalali'

/**
 * تقویمِ جلالیِ رابط (۱۳/۷؛ ایرادات ۱۰، بندهای ۷۳ و ۱۰۱) — با سرور، روز به روز.
 *
 * فهرستِ مرجع را `morilog/jalali` ِ سرور ساخته (`JalaliAnchorsTest`): اگر رابط حتی یک روز را جابه‌جا بشمارد، یادآورِ
 * «۳۰ مهر» در سرور «۲۹ مهر» ثبت می‌شد.
 */
const anchors = JSON.parse(readFileSync('tests/fixtures/jalali-anchors.json', 'utf8')) as {
  days: [string, string][]
  leapYears: number[]
}

describe('تقویمِ جلالی', () => {
  it('هر روزِ فهرستِ مرجعِ سرور (۱۹۸۰ تا ۲۰۴۵ و هر نوروز) همان روز است', () => {
    const wrong = anchors.days.filter(([gregorian, jalali]) => {
      const { jy, jm, jd } = fromIso(gregorian)
      return `${jy}-${String(jm).padStart(2, '0')}-${String(jd).padStart(2, '0')}` !== jalali
    })

    expect(anchors.days.length).toBeGreaterThan(4800)
    expect(wrong).toEqual([])
  })

  it('رفت‌وبرگشت: هر روزِ ۱۳۵۰ تا ۱۴۵۰ همان روز برمی‌گردد و روزها پیوسته‌اند', () => {
    let previous: number | null = null
    for (let jy = 1350; jy <= 1450; jy++) {
      for (let jm = 1; jm <= 12; jm++) {
        for (let jd = 1; jd <= jalaliMonthLength(jy, jm); jd++) {
          const { gy, gm, gd } = toGregorian(jy, jm, jd)
          expect(toJalali(gy, gm, gd)).toEqual({ jy, jm, jd })

          const day = Date.UTC(gy, gm - 1, gd) / 86_400_000
          if (previous !== null) expect(day - previous).toBe(1)
          previous = day
        }
      }
    }
  })

  it('کبیسه همان سال‌های سرور است؛ اسفندِ کبیسه ۳۰ روز، وگرنه ۲۹', () => {
    const leap: number[] = []
    for (let jy = 1300; jy <= 1500; jy++) if (isJalaliLeap(jy)) leap.push(jy)

    expect(leap).toEqual(anchors.leapYears)
    expect([jalaliMonthLength(1403, 12), jalaliMonthLength(1404, 12)]).toEqual([30, 29])
    expect([jalaliMonthLength(1405, 1), jalaliMonthLength(1405, 7)]).toEqual([31, 30])
  })

  it('لنگرهای شناخته‌شده و مرزِ سال', () => {
    expect(fromIso('2025-03-20')).toEqual({ jy: 1403, jm: 12, jd: 30 })
    expect(fromIso('2025-03-21')).toEqual({ jy: 1404, jm: 1, jd: 1 })
    expect(isoOf(1405, 8, 5)).toBe('2026-10-27')
    expect(isoOf(1404, 3, 17)).toBe('2025-06-07')
  })

  it('روزِ هفته از شنبه: ۵ آبانِ ۱۴۰۵ سه‌شنبه، نوروزِ ۱۴۰۴ جمعه', () => {
    expect(jalaliWeekday(1405, 8, 5)).toBe(3)
    expect(jalaliWeekday(1404, 1, 1)).toBe(6)
  })

  it('امروز به وقتِ تهران — نیمه‌شبِ UTC هنوز دیروزِ جهانی ولی امروزِ تهران است', () => {
    expect(todayInTehran(new Date('2026-10-26T21:00:00Z'))).toEqual({ jy: 1405, jm: 8, jd: 5 })
    expect(todayInTehran(new Date('2026-10-26T20:00:00Z'))).toEqual({ jy: 1405, jm: 8, jd: 4 })
  })
})
