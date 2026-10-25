import { z } from 'zod/mini'

/**
 * اعتبارسنجیِ بازیابیِ رمز (R40).
 *
 * ─── چرا قاعده‌ها اینجا تکرار شده‌اند و از `loginSchema` نمی‌آیند ──────────
 * ⚠️ عمدی است. پیامِ خطای هر فرم متعلق به همان فرم است و اشتراکِ زودهنگام،
 * تغییرِ یکی را به دیگری سرایت می‌دهد. الگوی شماره یکی است چون **قاعده‌ی
 * سرور** یکی است، نه چون کد را share کرده‌ایم — و اگر روزی سرور برای
 * بازیابی قیدِ دیگری بگذارد، اینجا مستقل عوض می‌شود.
 *
 * ─── چه چیزی جایگزین شد ────────────────────────────────────────────────────
 * پیش از این هر دو گام با `useState` و بررسیِ دستی کار می‌کردند، و دکمه‌ی
 * ارسال با `disabled={phone.length < 11}` غیرفعال می‌ماند — یعنی کاربر
 * دکمه‌ی خاموش می‌دید بی‌آنکه بداند **چرا**. حالا پیامِ روشن زیرِ فیلد
 * می‌آید.
 *
 * ⚠️ `zod/mini` و نه `zod` (به‌روزرسانیِ امنیتیِ zod ۴.۶.۶، ۲۰۲۶-۱۰-۲۵): این فایل در بارگذاریِ اولِ صفحه است و
 * نسخه‌ی کاملِ zod از ۴.۵ به بعد مولدِ JSON Schema و پیام‌های انگلیسی را همیشه با خود می‌آورد (حدودِ ۶٫۵ کیلوبایتِ
 * gzip). قاعده‌ها و پیام‌ها عیناً همان‌اند؛ صفحه‌های تنبل همچنان `zod` ِ کامل را به کار می‌برند.
 */

export const forgotPhoneSchema = z.object({
  phone: z
    .string()
    .check(
      z.minLength(1, 'شماره موبایل را وارد کنید'),
      z.regex(/^09\d{9}$/, 'شماره موبایل باید به‌فرمت ۰۹xxxxxxxxx باشد'),
    ),
})

export type ForgotPhoneValues = z.infer<typeof forgotPhoneSchema>

export const resetPasswordSchema = z
  .object({
    password: z.string().check(
      z.minLength(8, 'رمز عبور باید حداقل ۸ کاراکتر باشد'),
      // آینه‌ی قاعده‌ی سرور: حرف و عدد الزامی است
      z.regex(/[A-Za-z]/, 'رمز باید شامل حرف انگلیسی باشد'),
      z.regex(/\d/, 'رمز باید شامل عدد باشد'),
    ),
    confirmPassword: z.string().check(z.minLength(1, 'تکرار رمز عبور را وارد کنید')),
  })
  .check(
    z.refine((data) => data.password === data.confirmPassword, {
      message: 'رمز عبور و تکرار آن یکسان نیستند',
      path: ['confirmPassword'],
    }),
  )

export type ResetPasswordValues = z.infer<typeof resetPasswordSchema>
