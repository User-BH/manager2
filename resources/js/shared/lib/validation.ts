import { z } from 'zod'
import { toEnglishDigits } from './format'

/**
 * قواعد اعتبارسنجی مشترک فرم‌ها.
 *
 * همه جا یک تعریف: اگر هر فرم regex خودش را می‌نوشت، «کد ملی معتبر» در دو
 * صفحه دو معنی پیدا می‌کرد. اینجا هم regex هست هم بررسی رقم کنترلی، تا پیام
 * خطای دقیق زیر همان فیلد بنشیند.
 */

/** فقط حرف (فارسی یا لاتین)، فاصله و نیم‌فاصله؛ بدون رقم و نماد. */
export const NAME_PATTERN = /^[\p{L}\s‌'-]+$/u

/** موبایل ایران: ۱۱ رقم با شروع ۰۹. */
export const MOBILE_PATTERN = /^09\d{9}$/

/** تلفن ثابت یا موبایل: ۱۱ رقم با شروع ۰. */
export const PHONE_PATTERN = /^0\d{10}$/

/**
 * اعتبارسنجی کد ملی ایران با رقم کنترلی.
 *
 * فقط ۱۰ رقم بودن کافی نیست؛ رقم آخر باید با باقی‌مانده‌ی وزنیِ ۹ رقم اول
 * بخواند. ارقام تکراری (مثل ۰۰۰۰۰۰۰۰۰۰) هم که از این فرمول رد می‌شوند
 * دستی رد می‌شوند.
 */
export function isValidNationalId(input: string): boolean {
  const code = toEnglishDigits(input).trim()
  if (!/^\d{10}$/.test(code)) return false

  // ارقام یکسان از فرمول رد می‌شوند ولی کد واقعی نیستند
  if (/^(\d)\1{9}$/.test(code)) return false

  const check = Number(code[9])
  let sum = 0
  for (let i = 0; i < 9; i++) {
    sum += Number(code[i]) * (10 - i)
  }
  const remainder = sum % 11

  return remainder < 2 ? check === remainder : check === 11 - remainder
}

/** نام و نام خانوادگی. */
export const nameField = z
  .string()
  .trim()
  .min(3, 'نام و نام خانوادگی را کامل وارد کنید.')
  .max(255, 'نام بیش از حد طولانی است.')
  .regex(NAME_PATTERN, 'نام فقط می‌تواند شامل حروف باشد.')

/** ایمیل اختیاری. */
export const optionalEmail = z.union([
  z.literal(''),
  z.string().trim().email('ایمیل معتبر نیست.'),
])

/** کد ملی اختیاری با بررسی رقم کنترلی. */
export const optionalNationalId = z
  .union([z.literal(''), z.string().trim()])
  .refine((value) => value === '' || isValidNationalId(value), {
    message: 'کد ملی معتبر نیست.',
  })

/** شماره تماس اضطراری اختیاری (موبایل یا ثابت). */
export const optionalPhone = z
  .union([z.literal(''), z.string().trim()])
  .refine((value) => value === '' || PHONE_PATTERN.test(toEnglishDigits(value)), {
    message: 'شماره تماس معتبر نیست (۱۱ رقم با شروع ۰).',
  })

/**
 * رمز عبور قوی: حداقل ۸ نویسه، دست‌کم یک حرف و یک رقم.
 *
 * همان چیزی که سمت سرور با Password::min(8)->letters()->numbers() اعمال
 * می‌شود، تا کاربر پیش از رفتن به سرور همان‌جا خطا را ببیند.
 */
export const strongPassword = z
  .string()
  .min(8, 'رمز عبور حداقل ۸ نویسه باشد.')
  .regex(/[A-Za-z؀-ۿ]/, 'رمز عبور باید دست‌کم یک حرف داشته باشد.')
  .regex(/\d/, 'رمز عبور باید دست‌کم یک رقم داشته باشد.')
