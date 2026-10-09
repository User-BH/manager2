import { z } from 'zod'

const phone = z
  .string()
  .min(1, 'شماره موبایل را وارد کنید')
  .regex(/^09\d{9}$/, 'شماره موبایل باید به‌فرمت ۰۹xxxxxxxxx باشد')

const role = z.enum(['owner', 'tenant'], { message: 'نقش را انتخاب کنید' })

// F6 (مرحلهٔ ۹ · فاز ۶): مالک و مستأجر بی‌واحد نیستند
const unitId = z.string().min(1, 'واحد را انتخاب کنید')

/**
 * «ساکن جدید» = دعوت به شماره (مرحلهٔ ۹ · فاز ۶) — آینه‌ی `StoreResidentRequest`.
 *
 * نه نام و نه رمز: حساب را صاحبِ شماره خودش با OTP می‌سازد (F5).
 */
export const inviteResidentSchema = z.object({ phone, role, unit_id: unitId })

export type InviteResidentValues = z.infer<typeof inviteResidentSchema>

/**
 * ویرایشِ ساکن — آینه‌ی `UpdateResidentRequest`.
 *
 * شماره و رمز مالِ صاحبِ حساب‌اند و اینجا نیستند (تصاحبِ حساب).
 */
export const editResidentSchema = z.object({
  name: z.string().min(1, 'نام را وارد کنید').max(120, 'نام طولانی است'),
  email: z.union([z.literal(''), z.string().email('ایمیل معتبر نیست')]).optional(),
  national_id: z.string().max(20, 'کد ملی طولانی است').optional(),
  role,
  unit_id: unitId,
})

export type EditResidentValues = z.infer<typeof editResidentSchema>
