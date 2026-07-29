import { z } from 'zod'

/**
 * اعتبارسنجی فرم بنر تبلیغاتی.
 *
 * همین قواعد سمت سرور هم اعمال می‌شوند (`Api\System\AdvertisementController`)؛
 * این لایه فقط برای بازخورد فوری است و جای آن را نمی‌گیرد.
 */
export const adSchema = z
  .object({
    title: z.string().trim().min(3, 'عنوان حداقل ۳ نویسه باشد').max(150, 'عنوان طولانی است'),
    subtitle: z.string().trim().max(255, 'توضیح کوتاه طولانی است').optional().or(z.literal('')),
    href: z
      .string()
      .trim()
      .min(1, 'لینک مقصد الزامی است')
      // فقط http/https؛ مقادیری مثل javascript: نباید روی صفحه‌ی عمومی بنشینند
      .regex(/^https?:\/\/[^\s]+\.[^\s]{2,}/i, 'لینک باید کامل و با http:// یا https:// باشد'),
    sortOrder: z
      .number({ message: 'ترتیب باید عدد باشد' })
      .int('ترتیب باید عدد صحیح باشد')
      .min(0, 'ترتیب نمی‌تواند منفی باشد')
      .max(999, 'ترتیب حداکثر ۹۹۹ است'),
    isActive: z.boolean(),
    startsAt: z.string(),
    endsAt: z.string(),
  })
  .refine((v) => !v.startsAt || !v.endsAt || v.endsAt > v.startsAt, {
    message: 'تاریخ پایان باید بعد از تاریخ شروع باشد',
    path: ['endsAt'],
  })

export type AdFormValues = z.infer<typeof adSchema>

export interface AdItem {
  id: number
  title: string
  subtitle: string | null
  href: string
  image: string | null
  isActive: boolean
  /** فعال بودن کافی نیست؛ بازه‌ی تاریخ هم باید اجازه بدهد. */
  isLive: boolean
  sortOrder: number
  startsAt: string | null
  endsAt: string | null
  startsAtLabel: string | null
  endsAtLabel: string | null
  /** بنرهای پیش‌فرضِ همراه پروژه که فایل آپلودی ندارند. */
  isBuiltIn: boolean
}

export const emptyAd: AdFormValues = {
  title: '',
  subtitle: '',
  href: '',
  sortOrder: 0,
  isActive: true,
  startsAt: '',
  endsAt: '',
}
