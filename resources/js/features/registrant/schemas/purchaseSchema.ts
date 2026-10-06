import { z } from 'zod'

/**
 * خریدِ حسابِ اولیه (مرحلهٔ ۹ · فاز ۵) — فقط برای پاسخِ فوری.
 *
 * ⚠️ سرور هیچ‌کدام را باور نمی‌کند: `StartCheckoutRequest` و `ComplexPurchase`
 * نام، نوع و پلن را دوباره می‌سنجند و مبلغ را از خودِ پلن می‌خوانند.
 */
export const purchaseSchema = z.object({
  complexName: z
    .string()
    .trim()
    .min(2, 'نامِ مجتمع را وارد کنید.')
    .max(150, 'نامِ مجتمع بیش از ۱۵۰ نویسه است.'),
  complexType: z.enum(['residential', 'commercial'], { message: 'نوعِ مجتمع را انتخاب کنید.' }),
  address: z.string().trim().max(255, 'نشانی بیش از ۲۵۵ نویسه است.'),
  plan: z.string().min(1, 'یک پکیج انتخاب کنید.'),
})

export type PurchaseValues = z.infer<typeof purchaseSchema>
