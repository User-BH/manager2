import { z } from 'zod'

export const loginSchema = z.object({
  phone: z
    .string()
    .min(1, 'شماره موبایل را وارد کنید')
    .regex(/^09\d{9}$/, 'شماره موبایل باید به‌فرمت ۰۹xxxxxxxxx باشد'),
  password: z.string().min(6, 'رمز عبور باید حداقل ۶ کاراکتر باشد'),
  remember: z.boolean().optional(),
})

export type LoginFormValues = z.infer<typeof loginSchema>
