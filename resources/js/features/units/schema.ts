import { z } from 'zod'

/**
 * قواعد اینجا عمداً آینه‌ی اعتبارسنجی سمت سرور (Api\UnitController) هستند تا
 * کاربر خطا را قبل از رفت‌وبرگشت شبکه ببیند. سرور همچنان منبع نهایی است.
 */
export const unitSchema = z.object({
  unit_number: z.string().min(1, 'شماره واحد را وارد کنید').max(20, 'شماره واحد طولانی است'),
  building_id: z.string().optional(),
  floor: z.coerce.number({ message: 'طبقه را وارد کنید' }).int('طبقه باید عدد صحیح باشد').min(-5).max(200),
  area: z.coerce.number({ message: 'متراژ را وارد کنید' }).min(0, 'متراژ نمی‌تواند منفی باشد'),
  residents_count: z.coerce.number({ message: 'تعداد ساکنین را وارد کنید' }).int().min(0),
  parking_count: z.coerce.number().int().min(0).optional(),
  occupancy_status: z.string().min(1, 'وضعیت سکونت را انتخاب کنید'),
  coefficient: z.coerce.number({ message: 'ضریب را وارد کنید' }).min(0),
  uses_elevator: z.boolean().optional(),
  notes: z.string().max(255, 'توضیحات طولانی است').optional(),
})

/**
 * ورودی فرم با خروجی آن یکی نیست: مقدارهای عددی از input متنی می‌آیند و
 * z.coerce آن‌ها را تبدیل می‌کند. React Hook Form برای همین حالت سه ژنریک
 * می‌گیرد تا هم مقدار اولیه و هم مقدار نهایی درست تایپ شوند.
 */
export type UnitFormInput = z.input<typeof unitSchema>
export type UnitFormValues = z.output<typeof unitSchema>
