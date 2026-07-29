import { describe, expect, it } from 'vitest'
import {
  filterAsciiPassword,
  filterMobile,
  filterOtp,
  filterPersianLetters,
  toAsciiDigits,
} from '@/shared/lib/inputFilters'

/**
 * پالایه‌های ورودی، اولین خطِ دفاع فرم‌ها هستند: نویسه‌ی نامجاز اصلاً وارد
 * فیلد نمی‌شود. این تست‌ها همان قواعدی را می‌سنجند که کاربر در فرم می‌بیند.
 */

describe('toAsciiDigits', () => {
  it('ارقام فارسی را به لاتین تبدیل می‌کند', () => {
    expect(toAsciiDigits('۰۹۱۲۳۴۵۶۷۸۹')).toBe('09123456789')
  })

  it('ارقام عربی را هم تبدیل می‌کند', () => {
    expect(toAsciiDigits('٠١٢٣٤٥٦٧٨٩')).toBe('0123456789')
  })

  it('نویسه‌های غیررقمی را دست‌نخورده می‌گذارد', () => {
    expect(toAsciiDigits('کد: ۱۲۳')).toBe('کد: 123')
  })
})

describe('filterMobile', () => {
  it('شماره‌ی درست را بدون تغییر و بدون هشدار می‌پذیرد', () => {
    const result = filterMobile('09123456789')
    expect(result.value).toBe('09123456789')
    expect(result.changed).toBe(false)
  })

  it('ارقام فارسی را بی‌سروصدا نرمال می‌کند (بدون هشدار)', () => {
    const result = filterMobile('۰۹۱۲۳۴۵۶۷۸۹')
    expect(result.value).toBe('09123456789')
    // تبدیل رقم فارسی «خطای کاربر» نیست، پس نباید پیام هشدار بدهد
    expect(result.changed).toBe(false)
  })

  it('حروف را حذف می‌کند و پیام «فقط عدد» می‌دهد', () => {
    const result = filterMobile('0912abc3456')
    expect(result.value).toBe('09123456')
    expect(result.changed).toBe(true)
    expect(result.hint).toBe('فقط عدد وارد کنید.')
  })

  it('بیش از ۱۱ رقم را می‌بُرد و پیامِ مخصوصِ طول می‌دهد', () => {
    const result = filterMobile('0912345678999')
    expect(result.value).toBe('09123456789')
    expect(result.value).toHaveLength(11)
    expect(result.changed).toBe(true)
    // این همان باگی بود که پیام اشتباهِ «فقط عدد» می‌داد
    expect(result.hint).toBe('شماره موبایل بیش از ۱۱ رقم نیست.')
  })
})

describe('filterPersianLetters', () => {
  it('حروف فارسی و فاصله را نگه می‌دارد', () => {
    const result = filterPersianLetters('علی محمدی')
    expect(result.value).toBe('علی محمدی')
    expect(result.changed).toBe(false)
  })

  it('حروف انگلیسی و اعداد را حذف می‌کند', () => {
    const result = filterPersianLetters('علی Ali ۱۲۳')
    expect(result.value).not.toMatch(/[A-Za-z]/)
    expect(result.value).not.toMatch(/[0-9۰-۹]/)
    expect(result.changed).toBe(true)
  })
})

describe('filterAsciiPassword', () => {
  it('حروف انگلیسی، عدد و نماد را می‌پذیرد', () => {
    const result = filterAsciiPassword('Secret123!@#')
    expect(result.value).toBe('Secret123!@#')
    expect(result.changed).toBe(false)
  })

  it('حروف فارسی را از رمز حذف می‌کند', () => {
    const result = filterAsciiPassword('Secret123رمز')
    expect(result.value).toBe('Secret123')
    expect(result.changed).toBe(true)
  })
})

describe('filterOtp', () => {
  it('فقط رقم نگه می‌دارد و سقف طول را رعایت می‌کند', () => {
    const result = filterOtp('12a34b56789')
    expect(result.value).toBe('123456')
    expect(result.value).toHaveLength(6)
  })

  it('کدِ فارسی‌رقم را می‌پذیرد', () => {
    expect(filterOtp('۱۲۳۴۵۶').value).toBe('123456')
  })
})
