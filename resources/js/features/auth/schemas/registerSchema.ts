import { z } from 'zod/mini'
import { personNamePart } from '@/shared/lib/personName'

/**
 * فرمِ ثبت‌نام — بی رمز (۲۰۲۶-۱۰-۳۰): نام، شماره و پذیرشِ قوانین؛ شماره با کدِ پیامکی ثابت می‌شود و همان ورود است.
 *
 * ⚠️ `zod/mini` و نه `zod` (به‌روزرسانیِ امنیتیِ zod ۴.۶.۶، ۲۰۲۶-۱۰-۲۵): این فایل در بارگذاریِ اولِ صفحه است و
 * نسخه‌ی کاملِ zod از ۴.۵ به بعد مولدِ JSON Schema و پیام‌های انگلیسی را همیشه با خود می‌آورد (حدودِ ۶٫۵ کیلوبایتِ
 * gzip). قاعده‌ها و پیام‌ها عیناً همان‌اند؛ صفحه‌های تنبل همچنان `zod` ِ کامل را به کار می‌برند.
 */

export const registerSchema = z.object({
  // جدا و فقط فارسی (مرحلهٔ ۹ · فاز ۳؛ تصمیمِ ۱۲) — همان قاعده‌ی سرور
  firstName: personNamePart('نام'),
  lastName: personNamePart('نام خانوادگی'),
  phone: z
    .string()
    .check(
      z.minLength(1, 'شماره موبایل را وارد کنید'),
      z.regex(/^09\d{9}$/, 'شماره موبایل باید به‌فرمت ۰۹xxxxxxxxx باشد'),
    ),
  acceptTerms: z
    .boolean()
    .check(z.refine((val) => val === true, { message: 'برای ادامه باید قوانین را بپذیرید' })),
})

export type RegisterFormValues = z.infer<typeof registerSchema>
