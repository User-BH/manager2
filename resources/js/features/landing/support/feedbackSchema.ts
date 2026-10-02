import { z } from 'zod'
import { formatNumber } from '@/shared/lib/format'
import { toAsciiDigits } from '@/shared/lib/inputFilters'

/**
 * فرمِ بازخورد — اعتبارسنجیِ سمتِ کاربر (مرحلهٔ ۷ · فاز ۴).
 *
 * ⚠️ این فقط برای پاسخِ فوری است. همان قاعده‌ها در `StoreFeedbackRequest`
 * دوباره سنجیده می‌شوند و سرور به هیچ‌کدامِ این‌ها اعتماد نمی‌کند.
 */
export const FEEDBACK_CATEGORIES = [
  { value: 'feedback', label: 'بازخورد' },
  { value: 'feature', label: 'پیشنهاد یا درخواستِ قابلیتِ تازه' },
  { value: 'improvement', label: 'پیشنهادِ بهبود' },
  { value: 'criticism', label: 'انتقاد' },
  { value: 'bug', label: 'گزارشِ مشکل' },
] as const

export const MESSAGE_MAX = 2000
export const MAX_LINKS = 2

const categoryValues = FEEDBACK_CATEGORIES.map((c) => c.value) as [string, ...string[]]

export const feedbackSchema = z.object({
  category: z.enum(categoryValues, { message: 'نوعِ پیام را انتخاب کنید.' }),
  message: z
    .string()
    .trim()
    .min(10, 'متنِ پیام دستِ‌کم ۱۰ نویسه باشد.')
    .max(MESSAGE_MAX, `متنِ پیام حداکثر ${formatNumber(MESSAGE_MAX)} نویسه باشد.`)
    .refine(
      (value) => (value.match(/https?:\/\/|www\./gi) ?? []).length <= MAX_LINKS,
      `حداکثر ${formatNumber(MAX_LINKS)} پیوند در پیام مجاز است.`,
    ),
  name: z.string().trim().max(80, 'نام حداکثر ۸۰ نویسه باشد.'),
  phone: z
    .string()
    .trim()
    .refine(
      (value) => value === '' || /^09\d{9}$/.test(toAsciiDigits(value)),
      'شماره موبایل باید ۱۱ رقم و با ۰۹ شروع شود.',
    ),
  // تله‌ی ربات؛ انسان نمی‌بیندش
  website: z.string(),
})

export type FeedbackValues = z.infer<typeof feedbackSchema>
