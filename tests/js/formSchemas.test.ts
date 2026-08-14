import { describe, expect, it } from 'vitest'

import {
  ACCEPTED_RECEIPT_TYPES,
  MAX_RECEIPT_MB,
  receiptSchema,
} from '@/features/account/schemas/receiptSchema'
import { forgotPhoneSchema, resetPasswordSchema } from '@/features/auth/schemas/forgotSchema'

/**
 * قواعدِ اعتبارسنجیِ فرم‌هایی که در R40 به RHF+Zod منتقل شدند.
 *
 * ─── چرا اسکیما جدا آزموده می‌شود ──────────────────────────────────────────
 * ⚠️ پیش از این هر قاعده یک `if` داخلِ کامپوننت بود، پس آزمودنش یعنی
 * رندرکردنِ کلِ فرم، ساختنِ فایلِ ساختگی، و کلیک. حالا خودِ قاعده مستقیم
 * سنجیده می‌شود و تست‌ها هم سریع‌اند هم دقیق.
 */

/** فایلِ ساختگی با نوع و حجمِ دلخواه. */
function fakeFile(type: string, sizeMb: number): File {
  const file = new File(['x'], 'receipt', { type })

  // `size` فقط-خواندنی است؛ برای آزمونِ سقفِ حجم بازتعریف می‌شود
  Object.defineProperty(file, 'size', { value: sizeMb * 1024 * 1024 })

  return file
}

const validReceipt = {
  plan: 'pro',
  paidOn: '',
  note: '',
  receipt: fakeFile('image/jpeg', 1),
}

describe('اسکیمای رسیدِ اشتراک', () => {
  it('رسیدِ درست را می‌پذیرد', () => {
    expect(receiptSchema.safeParse(validReceipt).success).toBe(true)
  })

  /**
   * ⚠️ نبودِ فایل باید **پیش از** بررسیِ نوع پیام بدهد.
   *
   * در نسخه‌ی دستی، ترتیبِ بررسی‌ها تصادفی بود: چون `pickFile` نوع را
   * می‌سنجید و `submit` نبودن را، کاربری که هیچ فایلی نگذاشته بود پیامِ
   * «فقط JPG/PNG/PDF» می‌گرفت.
   */
  it('نبودِ فایل پیامِ روشنِ خودش را دارد', () => {
    const result = receiptSchema.safeParse({ ...validReceipt, receipt: undefined })

    expect(result.success).toBe(false)
    expect(result.error?.issues[0].message).toContain('انتخاب کنید')
  })

  it('نوعِ نامعتبر رد می‌شود', () => {
    const result = receiptSchema.safeParse({ ...validReceipt, receipt: fakeFile('image/gif', 1) })

    expect(result.success).toBe(false)
    expect(result.error?.issues[0].message).toContain('PDF')
  })

  it('هر سه نوعِ مجاز پذیرفته می‌شوند', () => {
    for (const type of ACCEPTED_RECEIPT_TYPES) {
      expect(receiptSchema.safeParse({ ...validReceipt, receipt: fakeFile(type, 1) }).success).toBe(
        true,
      )
    }
  })

  it('حجمِ بیش از سقف رد می‌شود', () => {
    const tooBig = receiptSchema.safeParse({
      ...validReceipt,
      receipt: fakeFile('application/pdf', MAX_RECEIPT_MB + 0.1),
    })

    expect(tooBig.success).toBe(false)
    expect(tooBig.error?.issues[0].message).toContain('مگابایت')

    // دقیقاً روی سقف باید بگذرد، نه اینکه رد شود
    const exact = receiptSchema.safeParse({
      ...validReceipt,
      receipt: fakeFile('application/pdf', MAX_RECEIPT_MB),
    })

    expect(exact.success).toBe(true)
  })

  it('توضیحِ بیش از حد بلند رد می‌شود', () => {
    const result = receiptSchema.safeParse({ ...validReceipt, note: 'ا'.repeat(501) })

    expect(result.success).toBe(false)
  })

  it('تاریخِ خالی مجاز است — سرور خودش تاریخِ ثبت را می‌گذارد', () => {
    expect(receiptSchema.safeParse({ ...validReceipt, paidOn: '' }).success).toBe(true)
  })
})

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
