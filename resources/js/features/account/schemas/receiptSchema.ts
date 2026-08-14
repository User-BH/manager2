import { z } from 'zod'

/**
 * اعتبارسنجیِ فرمِ آپلودِ رسیدِ اشتراک (R40).
 *
 * ─── چرا اسکیمای جدا و نه بررسیِ دستی ──────────────────────────────────────
 * پیش از این هر قاعده در یک `if` جداگانه‌ی داخلِ کامپوننت بود و پیامش هم
 * همان‌جا نوشته می‌شد. دو مشکل داشت: قاعده‌ها قابلِ آزمون نبودند مگر با
 * رندرکردنِ کلِ فرم، و ترتیبِ بررسی‌ها تصادفی بود — فایلِ نامعتبر پیش از
 * نبودِ فایل بررسی می‌شد.
 *
 * ─── قیدها آینه‌ی سرورند ───────────────────────────────────────────────────
 * ⚠️ این‌ها **جایگزینِ** بررسیِ سرور نیستند؛ سرور دوباره همه را می‌سنجد.
 * کارشان فقط بازخوردِ فوری است، پیش از آنکه کاربر چهار مگابایت آپلود کند و
 * بعد رد شود.
 */

/** همان سقفِ `StoreSubscriptionReceiptRequest`. */
export const MAX_RECEIPT_MB = 4

/** همان فهرستِ سرور؛ تصویر یا PDF. */
export const ACCEPTED_RECEIPT_TYPES = ['image/jpeg', 'image/png', 'application/pdf']

export const receiptSchema = z.object({
  plan: z.string().min(1, 'یکی از پلن‌ها را انتخاب کنید'),

  /** تاریخ اختیاری است؛ سرور اگر نیاید تاریخِ ثبت را می‌گذارد. */
  paidOn: z.string(),

  note: z.string().max(500, 'توضیح نباید از ۵۰۰ نویسه بیشتر باشد'),

  /*
   * ⚠️ `File` در محیطِ تست (jsdom) وجود دارد ولی در هر محیطی نه؛ پس
   * به‌جای `z.instanceof(File)` که آنجا می‌ترکد، شکلِ لازم سنجیده می‌شود.
   */
  receipt: z
    .custom<File>((value) => typeof value === 'object' && value !== null && 'size' in value, {
      message: 'تصویر یا فایل رسید را انتخاب کنید.',
    })
    .refine((file) => ACCEPTED_RECEIPT_TYPES.includes(file.type), {
      message: 'فقط تصویر (JPG/PNG) یا فایل PDF پذیرفته می‌شود.',
    })
    .refine((file) => file.size <= MAX_RECEIPT_MB * 1024 * 1024, {
      message: `حجم فایل نباید از ${MAX_RECEIPT_MB} مگابایت بیشتر باشد.`,
    }),
})

export type ReceiptFormValues = z.infer<typeof receiptSchema>
