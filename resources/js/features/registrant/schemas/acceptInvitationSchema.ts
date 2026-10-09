import { z } from 'zod'

/**
 * کدِ پذیرشِ دعوت (مرحلهٔ ۹ · فاز ۶) — فقط برای پاسخِ فوری.
 *
 * ⚠️ سرور خودش کد را با `OtpService` می‌سنجد. رقمِ فارسی هم پذیرفته است و پیش
 * از ارسال لاتین می‌شود (`AcceptInvitationForm`).
 */
export const acceptInvitationSchema = z.object({
  code: z
    .string()
    .trim()
    .regex(/^[0-9۰-۹]{6}$/, 'کدِ شش‌رقمیِ پیامک را وارد کنید.'),
})

export type AcceptInvitationValues = z.infer<typeof acceptInvitationSchema>
