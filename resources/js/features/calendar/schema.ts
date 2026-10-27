import { z } from 'zod'

/**
 * فرمِ یادآور (۱۳/۷؛ ایرادات ۱۰، بندهای ۷۷ تا ۸۴) — فقط برای پاسخِ فوری.
 *
 * ⚠️ سرور همه را دوباره می‌سنجد (`SaveReminderRequest`): تاریخِ گذشته، یادآوری پس از رویداد، لحظه‌ی رفته. «زمانِ
 * یادآوری» این‌جا «چند روز قبل» است؛ تاریخِ دلخواه هم به همان تبدیل می‌شود.
 */
const number = (min: number, max: number, message: string) =>
  z
    .string()
    .refine((value) => /^\d+$/.test(value) && Number(value) >= min && Number(value) <= max, message)

export const reminderSchema = z
  .object({
    title: z
      .string()
      .trim()
      .min(2, 'موضوعِ یادآور را بنویسید.')
      .max(150, 'موضوع بیش از ۱۵۰ نویسه است.'),
    description: z.string().trim().max(1000, 'توضیحات بیش از ۱۰۰۰ نویسه است.'),
    lead: z.enum(['0', '1', '2', '3', '7', 'custom']),
    customLead: z.string(),
    remindTime: z.string().regex(/^([01]\d|2[0-3]):[0-5]\d$/, 'ساعت را به شکلِ ۰۹:۳۰ وارد کنید.'),
    repeat: z.enum(['once', 'every']),
    every: z.string(),
    end: z.enum(['none', 'until', 'count']),
    until: z.string(),
    count: z.string(),
  })
  .superRefine((values, ctx) => {
    if (values.lead === 'custom' && !number(0, 365, '').safeParse(values.customLead).success) {
      ctx.addIssue({
        code: 'custom',
        path: ['customLead'],
        message: 'تعدادِ روز را بین ۰ و ۳۶۵ وارد کنید.',
      })
    }
    if (values.repeat !== 'every') return
    if (!number(1, 365, '').safeParse(values.every).success) {
      ctx.addIssue({
        code: 'custom',
        path: ['every'],
        message: 'فاصله‌ی تکرار را بین ۱ و ۳۶۵ روز وارد کنید.',
      })
    }
    if (values.end === 'until' && values.until === '') {
      ctx.addIssue({
        code: 'custom',
        path: ['until'],
        message: 'تاریخِ پایانِ تکرار را انتخاب کنید.',
      })
    }
    if (values.end === 'count' && !number(2, 1000, '').safeParse(values.count).success) {
      ctx.addIssue({
        code: 'custom',
        path: ['count'],
        message: 'تعدادِ دفعات را بین ۲ و ۱۰۰۰ وارد کنید.',
      })
    }
  })

export type ReminderValues = z.infer<typeof reminderSchema>

export function leadDaysOf(values: ReminderValues): number {
  return values.lead === 'custom' ? Number(values.customLead) : Number(values.lead)
}
