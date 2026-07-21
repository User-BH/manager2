import { z } from 'zod'

export const registerSchema = z
  .object({
    fullName: z
      .string()
      .min(1, 'نام و نام خانوادگی را وارد کنید')
      .min(3, 'نام باید حداقل ۳ کاراکتر باشد'),
    phone: z
      .string()
      .min(1, 'شماره موبایل را وارد کنید')
      .regex(/^09\d{9}$/, 'شماره موبایل باید به‌فرمت ۰۹xxxxxxxxx باشد'),
    complexName: z.string().min(1, 'نام مجتمع را وارد کنید').min(2, 'نام مجتمع خیلی کوتاه است'),
    password: z.string().min(8, 'رمز عبور باید حداقل ۸ کاراکتر باشد'),
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
