import { describe, expect, it } from 'vitest'
import * as feedback from '@/features/landing/support/feedbackSchema'
import { PERSIAN_NAME_PATTERN, filterPersianName, personNamePart } from '@/shared/lib/personName'

/**
 * یک قاعده‌ی نام در دو نسخه (مرحلهٔ ۹ · فاز ۳).
 *
 * فرمِ بازخورد به‌خاطرِ بودجه‌ی باندلِ صفحه‌ی پشتیبانی نسخه‌ی خودش را دارد؛ این
 * تست نمی‌گذارد دو نسخه از هم جدا شوند — همان کاری که سرور با یک `PersianName` می‌کند.
 */
describe('نامِ فارسی: یک قاعده، دو نسخه', () => {
  const samples = ['علي', 'كريمی', 'Ali', 'علی۲', 'علی،؟', '  مریم   حسینی‌نژاد ', '<b>نام</b>', '']

  it('الگوی هر دو نسخه یکی است', () => {
    expect(feedback.PERSIAN_NAME_PATTERN.source).toBe(PERSIAN_NAME_PATTERN.source)
  })

  it.each(samples)('پالایه‌ی هر دو نسخه برای «%s» یک خروجی دارد', (value) => {
    expect(feedback.filterPersianName(value)).toEqual(filterPersianName(value))
  })

  it('بخشِ نام: حرفِ فارسی، دست‌کم ۲ و حداکثر ۶۰', () => {
    const part = personNamePart('نام')
    expect(part.safeParse('مریم').success).toBe(true)
    expect(part.safeParse('حسینی‌نژاد').success).toBe(true)
    for (const bad of ['م', 'Maryam', 'مریم۱', 'مریم!', 'ا'.repeat(61)]) {
      expect(part.safeParse(bad).success, bad).toBe(false)
    }
  })
})
