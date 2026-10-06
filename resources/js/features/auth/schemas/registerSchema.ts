import { z } from 'zod'
import { personNamePart } from '@/shared/lib/personName'

export const registerSchema = z
  .object({
    // جدا و فقط فارسی (مرحلهٔ ۹ · فاز ۳؛ تصمیمِ ۱۲) — همان قاعده‌ی سرور
    firstName: personNamePart('نام'),
    lastName: personNamePart('نام خانوادگی'),
    phone: z
      .string()
      .min(1, 'شماره موبایل را وارد کنید')
      .regex(/^09\d{9}$/, 'شماره موبایل باید به‌فرمت ۰۹xxxxxxxxx باشد'),
    password: z
      .string()
      .min(8, 'رمز عبور باید حداقل ۸ کاراکتر باشد')
      // آینه‌ی قاعده‌ی سرور: حرف و عدد الزامی است
      .regex(/[A-Za-z]/, 'رمز باید شامل حرف انگلیسی باشد')
      .regex(/\d/, 'رمز باید شامل عدد باشد'),
    confirmPassword: z.string().min(1, 'تکرار رمز عبور را وارد کنید'),
    acceptTerms: z.boolean().refine((val) => val === true, {
      message: 'برای ادامه باید قوانین را بپذیرید',
    }),
  })
  .refine((data) => data.password === data.confirmPassword, {
    message: 'رمز عبور و تکرار آن یکسان نیستند',
    path: ['confirmPassword'],
  })

export type RegisterFormValues = z.infer<typeof registerSchema>
