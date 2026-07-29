import { describe, expect, it } from 'vitest'
import { loginSchema } from '@/features/auth/schemas/loginSchema'
import { registerSchema } from '@/features/auth/schemas/registerSchema'

/**
 * این طرح‌ها آینه‌ی قواعدِ سمت سرورند. اگر اینجا سست شوند، کاربر فرمی می‌فرستد
 * که سرور ردش می‌کند و خطای گیج‌کننده می‌گیرد؛ پس مرزها باید تست شوند.
 */

const validRegister = {
  fullName: 'علی محمدی',
  phone: '09123456789',
  password: 'Secret123',
  confirmPassword: 'Secret123',
  acceptTerms: true,
}

describe('loginSchema', () => {
  it('ورودی درست را می‌پذیرد', () => {
    const result = loginSchema.safeParse({
      phone: '09123456789',
      password: 'secret123',
      remember: true,
    })
    expect(result.success).toBe(true)
  })

  it('شماره‌ی خالی را رد می‌کند', () => {
    const result = loginSchema.safeParse({ phone: '', password: 'secret123' })
    expect(result.success).toBe(false)
  })

  it('شماره‌ای که با ۰۹ شروع نمی‌شود را رد می‌کند', () => {
    const result = loginSchema.safeParse({ phone: '02112345678', password: 'secret123' })
    expect(result.success).toBe(false)
  })

  it('شماره‌ی کوتاه‌تر از ۱۱ رقم را رد می‌کند', () => {
    const result = loginSchema.safeParse({ phone: '0912345', password: 'secret123' })
    expect(result.success).toBe(false)
  })

  it('رمز کوتاه‌تر از ۶ نویسه را رد می‌کند', () => {
    const result = loginSchema.safeParse({ phone: '09123456789', password: '123' })
    expect(result.success).toBe(false)
  })

  it('«مرا به خاطر بسپار» اختیاری است', () => {
    const result = loginSchema.safeParse({ phone: '09123456789', password: 'secret123' })
    expect(result.success).toBe(true)
  })
})

describe('registerSchema', () => {
  it('ورودی کاملاً درست را می‌پذیرد', () => {
    expect(registerSchema.safeParse(validRegister).success).toBe(true)
  })

  it('ناهمخوانیِ رمز و تکرارش را روی فیلدِ تکرار گزارش می‌کند', () => {
    const result = registerSchema.safeParse({ ...validRegister, confirmPassword: 'Different1' })
    expect(result.success).toBe(false)
    if (!result.success) {
      // پیام باید زیرِ همان فیلدِ تکرار بنشیند، نه جای دیگر
      expect(result.error.issues.some((i) => i.path.includes('confirmPassword'))).toBe(true)
    }
  })

  it('نپذیرفتنِ قوانین را رد می‌کند', () => {
    const result = registerSchema.safeParse({ ...validRegister, acceptTerms: false })
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues.some((i) => i.path.includes('acceptTerms'))).toBe(true)
    }
  })

  it('رمزِ بدونِ عدد را رد می‌کند (آینه‌ی قاعده‌ی سرور)', () => {
    const result = registerSchema.safeParse({
      ...validRegister,
      password: 'OnlyLetters',
      confirmPassword: 'OnlyLetters',
    })
    expect(result.success).toBe(false)
  })

  it('رمزِ بدونِ حرف را رد می‌کند', () => {
    const result = registerSchema.safeParse({
      ...validRegister,
      password: '12345678',
      confirmPassword: '12345678',
    })
    expect(result.success).toBe(false)
  })

  it('رمزِ کوتاه‌تر از ۸ نویسه را رد می‌کند', () => {
    const result = registerSchema.safeParse({
      ...validRegister,
      password: 'Ab1',
      confirmPassword: 'Ab1',
    })
    expect(result.success).toBe(false)
  })

  it('نامِ کوتاه‌تر از ۳ نویسه را رد می‌کند', () => {
    expect(registerSchema.safeParse({ ...validRegister, fullName: 'ا' }).success).toBe(false)
  })
})
