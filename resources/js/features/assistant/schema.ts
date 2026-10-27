import { z } from 'zod'

/**
 * پرسشِ دستیار — فقط برای پاسخِ فوری؛ سرور همان را دوباره می‌سنجد (`AskAssistantRequest`). هیچ شناسه‌ای جز متن
 * فرستاده نمی‌شود: زمینه از نشستِ سرور است.
 */
export const questionSchema = z.object({
  question: z
    .string()
    .trim()
    .min(2, 'پرسش را بنویسید.')
    .max(300, 'پرسش بیش از ۳۰۰ نویسه است؛ کوتاه‌ترش کنید.'),
})

export type QuestionValues = z.infer<typeof questionSchema>
