import { z } from 'zod'
import { formatNumber } from '@/shared/lib/format'
import { toAsciiDigits, type FilterResult } from '@/shared/lib/inputFilters'

/**
 * فرمِ بازخورد — اعتبارسنجیِ سمتِ کاربر (مرحلهٔ ۷ · فاز ۴؛ مرحلهٔ ۸ · فاز ۵).
 *
 * ⚠️ این فقط برای پاسخِ فوری است. همان قاعده‌ها در `StoreFeedbackRequest`
 * دوباره سنجیده می‌شوند و سرور به هیچ‌کدامِ این‌ها اعتماد نمی‌کند.
 */

/**
 * نامِ فارسیِ سخت‌گیرانه (مرحلهٔ ۸ · فاز ۵ · بندِ ۹).
 *
 * ⚠️ این‌جا و نه در `inputFilters` ِ مشترک: آن فایل در بارگذاریِ اولِ صفحه‌ی
 * پشتیبانی هست و فرمِ بازخورد تنبل است — همین ‎۷۴ بایت بودجه‌ی صفحه را رد کرد.
 *
 * `filterPersianLetters` کلِ بلوکِ عربی را راه می‌دهد، که «،»، «؛»، «؟»، «٪»
 * و اعراب را هم دارد. این‌جا فقط **حرف**، فاصله و نیم‌فاصله؛ «ي/ك/ى» ِ عربی
 * به «ی/ک» ِ فارسی برمی‌گردند (تغییرِ نامجاز حساب نمی‌شود) و فاصله‌ی پشتِ‌هم یکی
 * می‌شود. همین مجموعه در `StoreFeedbackRequest` هم سنجیده می‌شود.
 */
export const PERSIAN_NAME_PATTERN = /^[ء-غف-يپچژکگی‌ ]+$/

export const PERSIAN_NAME_HINT = 'نام فقط با حروف فارسی؛ عدد، حرفِ انگلیسی و نماد پذیرفته نیست.'

export function filterPersianName(value: string): FilterResult {
  const normalized = value.replace(/[يى]/g, 'ی').replace(/ك/g, 'ک')
  const cleaned = normalized
    .replace(/[^ء-غف-يپچژکگی‌ ]/g, '')
    .replace(/ {2,}/g, ' ')
    .replace(/^ /, '')

  // فاصله‌ی تکراری و حرفِ عربی خطا نیستند؛ فقط نویسه‌ی حذف‌شده هشدار دارد
  const removed = normalized.replace(/ {2,}/g, ' ').replace(/^ /, '') !== cleaned
  return removed
    ? { value: cleaned, changed: true, hint: PERSIAN_NAME_HINT }
    : { value: cleaned, changed: false }
}

/**
 * نوعِ پیام (مرحلهٔ ۸ · فاز ۵ · بندِ ۷) — همان مقدارهای `FeedbackCategory`.
 *
 * پنج نوعِ قبلی نگه داشته شدند (پیام‌های ذخیره‌شده همین مقدارها را دارند) و
 * فقط برچسبشان با فهرستِ «بررسی جدید» یکی شد؛ «سایر» تنها نوعِ تازه است. هیچ
 * دو گزینه‌ای هم‌معنا نیستند.
 */
export const FEEDBACK_CATEGORIES = [
  { value: 'improvement', label: 'پیشنهاد', description: 'ایده‌ای برای بهتر شدنِ ساکنا' },
  { value: 'criticism', label: 'انتقاد', description: 'چیزی که از آن راضی نیستید' },
  { value: 'bug', label: 'گزارشِ مشکل', description: 'خطا یا چیزی که درست کار نمی‌کند' },
  { value: 'feature', label: 'درخواستِ قابلیت', description: 'امکانی که در ساکنا نیست' },
  {
    value: 'feedback',
    label: 'بازخورد درباره‌ی تجربه‌ی کاربری',
    description: 'کار با پنل، صفحه‌ها و طراحی',
  },
  { value: 'other', label: 'سایر', description: 'هر چیزِ دیگر' },
] as const

export const MESSAGE_MIN = 10
export const MESSAGE_MAX = 2000
export const NAME_MAX = 60
export const MAX_LINKS = 2

const categoryValues = FEEDBACK_CATEGORIES.map((c) => c.value) as [string, ...string[]]

export const feedbackSchema = z.object({
  category: z.enum(categoryValues, { message: 'نوعِ پیام را انتخاب کنید.' }),
  message: z
    .string()
    .trim()
    .min(MESSAGE_MIN, `متنِ پیام دستِ‌کم ${formatNumber(MESSAGE_MIN)} نویسه باشد.`)
    .max(MESSAGE_MAX, `متنِ پیام حداکثر ${formatNumber(MESSAGE_MAX)} نویسه باشد.`)
    .refine(
      (value) => (value.match(/https?:\/\/|www\./gi) ?? []).length <= MAX_LINKS,
      `حداکثر ${formatNumber(MAX_LINKS)} پیوند در پیام مجاز است.`,
    ),
  // اختیاری: خالی خطا نیست
  name: z
    .string()
    .trim()
    .max(NAME_MAX, `نام حداکثر ${formatNumber(NAME_MAX)} نویسه باشد.`)
    .refine(
      (value) => value === '' || PERSIAN_NAME_PATTERN.test(value),
      'نام فقط با حروف فارسی نوشته شود.',
    ),
  // اجباری (مرحلهٔ ۸ · فاز ۵ · بندِ ۱۰)
  phone: z
    .string()
    .trim()
    .min(1, 'شماره موبایل را وارد کنید.')
    .refine(
      (value) => /^09\d{9}$/.test(toAsciiDigits(value)),
      'شماره موبایل باید ۱۱ رقم و با ۰۹ شروع شود.',
    ),
  // تله‌ی ربات؛ انسان نمی‌بیندش
  website: z.string(),
})

export type FeedbackValues = z.infer<typeof feedbackSchema>
