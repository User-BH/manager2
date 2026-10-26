import { z } from 'zod'

/**
 * مشخصاتِ مجتمعِ تازه در گامِ «تأیید» ِ جادوگرِ خرید (حسابِ اولیه؛ ۱۳/۶) — فقط برای پاسخِ فوری.
 *
 * ⚠️ سرور هیچ‌کدام را باور نمی‌کند: `StartCheckoutRequest` و `ComplexPurchase` نام، نوع، پلن و مدت را دوباره می‌سنجند
 * و مبلغ را از جدولِ قیمت می‌خوانند.
 */
export const newComplexSchema = z.object({
  complexName: z
    .string()
    .trim()
    .min(2, 'نامِ مجتمع را وارد کنید.')
    .max(150, 'نامِ مجتمع بیش از ۱۵۰ نویسه است.'),
  address: z.string().trim().max(255, 'نشانی بیش از ۲۵۵ نویسه است.'),
})

/** تمدید یا تغییرِ پلنِ مجتمعِ موجود: مشخصاتی لازم نیست */
export const renewalSchema = z.object({
  complexName: z.string(),
  address: z.string(),
})

export type CheckoutDetails = z.infer<typeof newComplexSchema>
