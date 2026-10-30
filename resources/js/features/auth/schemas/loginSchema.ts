import { z } from 'zod/mini'

/**
 * فرمِ ورود با شماره — بی رمز (۲۰۲۶-۱۰-۳۰): گامِ دوم کدِ پیامکی است.
 *
 * ⚠️ `zod/mini` و نه `zod` (به‌روزرسانیِ امنیتیِ zod ۴.۶.۶، ۲۰۲۶-۱۰-۲۵): این فایل در بارگذاریِ اولِ صفحه است و
 * نسخه‌ی کاملِ zod از ۴.۵ به بعد مولدِ JSON Schema و پیام‌های انگلیسی را همیشه با خود می‌آورد (حدودِ ۶٫۵ کیلوبایتِ
 * gzip). قاعده‌ها و پیام‌ها عیناً همان‌اند؛ صفحه‌های تنبل همچنان `zod` ِ کامل را به کار می‌برند.
 */
export const loginSchema = z.object({
  phone: z
    .string()
    .check(
      z.minLength(1, 'شماره موبایل را وارد کنید'),
      z.regex(/^09\d{9}$/, 'شماره موبایل باید به‌فرمت ۰۹xxxxxxxxx باشد'),
    ),
  remember: z.optional(z.boolean()),
})

export type LoginFormValues = z.infer<typeof loginSchema>
