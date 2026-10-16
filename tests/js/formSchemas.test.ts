import { describe, expect, it } from 'vitest'

import { forgotPhoneSchema, resetPasswordSchema } from '@/features/auth/schemas/forgotSchema'

/**
 * قواعدِ اعتبارسنجیِ فرم‌هایی که در R40 به RHF+Zod منتقل شدند.
 *
 * ─── چرا اسکیما جدا آزموده می‌شود ──────────────────────────────────────────
 * ⚠️ پیش از این هر قاعده یک `if` داخلِ کامپوننت بود، پس آزمودنش یعنی
 * رندرکردنِ کلِ فرم، ساختنِ فایلِ ساختگی، و کلیک. حالا خودِ قاعده مستقیم
 * سنجیده می‌شود و تست‌ها هم سریع‌اند هم دقیق.
 */

/*
 * ۱۲/۲: اسکیمای رسیدِ **اشتراک** با خودِ آن مسیر رفت — خرید و تمدید فقط آنلاین‌اند. رسیدِ قبض (پرداختِ ساکن)
 * اسکیمای خودش را در `PayBillPage` دارد و دست نخورد.
 */

describe('اسکیمای بازیابیِ رمز — گامِ شماره', () => {
  it('شماره‌ی درست را می‌پذیرد', () => {
    expect(forgotPhoneSchema.safeParse({ phone: '09121234567' }).success).toBe(true)
  })

  it.each([
    ['', 'وارد کنید'],
    ['0912', 'فرمت'],
    ['08121234567', 'فرمت'],
    ['091212345678', 'فرمت'],
  ])('شماره‌ی «%s» رد می‌شود', (phone, expected) => {
    const result = forgotPhoneSchema.safeParse({ phone })

    expect(result.success).toBe(false)
    expect(result.error?.issues[0].message).toContain(expected)
  })
})

describe('اسکیمای بازیابیِ رمز — گامِ رمزِ تازه', () => {
  const valid = { password: 'abcd1234', confirmPassword: 'abcd1234' }

  it('رمزِ درست را می‌پذیرد', () => {
    expect(resetPasswordSchema.safeParse(valid).success).toBe(true)
  })

  it('رمزِ کوتاه رد می‌شود', () => {
    expect(
      resetPasswordSchema.safeParse({ password: 'ab12', confirmPassword: 'ab12' }).success,
    ).toBe(false)
  })

  it('رمزِ بدونِ عدد رد می‌شود', () => {
    const result = resetPasswordSchema.safeParse({
      password: 'abcdefgh',
      confirmPassword: 'abcdefgh',
    })

    expect(result.success).toBe(false)
    expect(result.error?.issues[0].message).toContain('عدد')
  })

  it('رمزِ بدونِ حرف رد می‌شود', () => {
    const result = resetPasswordSchema.safeParse({
      password: '12345678',
      confirmPassword: '12345678',
    })

    expect(result.success).toBe(false)
    expect(result.error?.issues[0].message).toContain('حرف')
  })

  /**
   * ⚠️ خطای ناهمخوانی باید روی **فیلدِ تکرار** بنشیند، نه فیلدِ رمز.
   *
   * در نسخه‌ی دستی این یک پیامِ شناور بالای دکمه بود و کاربر نمی‌دانست
   * کدام فیلد را باید درست کند.
   */
  it('ناهمخوانیِ رمز و تکرارش روی فیلدِ تکرار می‌نشیند', () => {
    const result = resetPasswordSchema.safeParse({
      password: 'abcd1234',
      confirmPassword: 'abcd9999',
    })

    expect(result.success).toBe(false)
    expect(result.error?.issues[0].path).toEqual(['confirmPassword'])
    expect(result.error?.issues[0].message).toContain('یکسان نیستند')
  })
})
