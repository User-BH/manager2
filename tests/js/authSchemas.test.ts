import { describe, expect, it } from 'vitest'
import { loginSchema } from '@/features/auth/schemas/loginSchema'
import { registerSchema } from '@/features/auth/schemas/registerSchema'

/**
 * این طرح‌ها آینه‌ی قواعدِ سمت سرورند. اگر اینجا سست شوند، کاربر فرمی می‌فرستد
 * که سرور ردش می‌کند و خطای گیج‌کننده می‌گیرد؛ پس مرزها باید تست شوند.
 */

const validRegister = {
  firstName: 'علی',
  lastName: 'محمدی',
  phone: '09123456789',
  acceptTerms: true,
}

describe('loginSchema', () => {
  it('ورودی درست را می‌پذیرد', () => {
    const result = loginSchema.safeParse({
      phone: '09123456789',
      remember: true,
    })
    expect(result.success).toBe(true)
  })

  it('شماره‌ی خالی را رد می‌کند', () => {
    const result = loginSchema.safeParse({ phone: '' })
    expect(result.success).toBe(false)
  })

  it('شماره‌ای که با ۰۹ شروع نمی‌شود را رد می‌کند', () => {
    const result = loginSchema.safeParse({ phone: '02112345678' })
    expect(result.success).toBe(false)
  })

  it('شماره‌ی کوتاه‌تر از ۱۱ رقم را رد می‌کند', () => {
    const result = loginSchema.safeParse({ phone: '0912345' })
    expect(result.success).toBe(false)
  })

  it('«مرا به خاطر بسپار» اختیاری است', () => {
    const result = loginSchema.safeParse({ phone: '09123456789' })
    expect(result.success).toBe(true)
  })
})

describe('registerSchema', () => {
  it('رمز ندارد (۲۰۲۶-۱۰-۳۰): فیلدِ رمزِ اضافه نادیده گرفته می‌شود', () => {
    const result = registerSchema.safeParse({ ...validRegister, password: 'x' })
    expect(result.success).toBe(true)
    if (result.success) expect(result.data).not.toHaveProperty('password')
  })

  it('ورودی کاملاً درست را می‌پذیرد', () => {
    expect(registerSchema.safeParse(validRegister).success).toBe(true)
  })

  it('نپذیرفتنِ قوانین را رد می‌کند', () => {
    const result = registerSchema.safeParse({ ...validRegister, acceptTerms: false })
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues.some((i) => i.path.includes('acceptTerms'))).toBe(true)
    }
  })

  it('نام و نام خانوادگی جدا، هر کدام دست‌کم ۲ حرف', () => {
    expect(registerSchema.safeParse({ ...validRegister, firstName: 'ا' }).success).toBe(false)
    expect(registerSchema.safeParse({ ...validRegister, lastName: '' }).success).toBe(false)
  })

  it('نام فقط با حروف فارسی — همان قاعده‌ی سرور', () => {
    for (const bad of ['Ali', 'علی۲', 'علی!', 'علی،']) {
      expect(registerSchema.safeParse({ ...validRegister, firstName: bad }).success, bad).toBe(
        false,
      )
    }
    expect(registerSchema.safeParse({ ...validRegister, lastName: 'حسینی‌نژاد' }).success).toBe(
      true,
    )
  })
})
