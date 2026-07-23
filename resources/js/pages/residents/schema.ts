import { z } from 'zod'
import { strongPassword } from '@/lib/validation'

/** آینه‌ی اعتبارسنجی Api\ResidentController. */
export function residentSchema(isEditing: boolean) {
  return z.object({
    name: z.string().min(1, 'نام را وارد کنید').max(120, 'نام طولانی است'),
    phone: z
      .string()
      .min(1, 'شماره موبایل را وارد کنید')
      .regex(/^09\d{9}$/, 'شماره موبایل باید به‌فرمت ۰۹xxxxxxxxx باشد'),
    email: z.union([z.literal(''), z.string().email('ایمیل معتبر نیست')]).optional(),
    national_id: z.string().max(20, 'کد ملی طولانی است').optional(),
    role: z.enum(['owner', 'tenant'], { message: 'نقش را انتخاب کنید' }),
    unit_id: z.string().optional(),
    // هنگام ویرایش، رمز خالی یعنی «تغییرش نده»
    // قاعده همان قاعده‌ی تغییر رمز در پروفایل است؛ حسابی که مدیر می‌سازد
    // نباید رمز ضعیف‌تری از حساب خودِ کاربر داشته باشد.
    password: isEditing
      ? z.union([z.literal(''), strongPassword]).optional()
      : strongPassword,
  })
}

export type ResidentFormValues = z.infer<ReturnType<typeof residentSchema>>
