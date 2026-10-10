import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2, Smartphone } from 'lucide-react'
import { z } from 'zod'
import { Card } from '@/shared/ui/Card'
import { TextField } from '@/shared/ui/Field'
import { api, ApiError } from '@/shared/lib/api'
import { alertError, toastSuccess } from '@/shared/lib/alert'
import { toEnglishDigits } from '@/shared/lib/format'
import { MOBILE_PATTERN } from '@/shared/lib/validation'

const phoneSchema = z.object({
  phone: z
    .string()
    .trim()
    .transform((value) => toEnglishDigits(value))
    .refine((value) => MOBILE_PATTERN.test(value), 'شماره باید به شکل ۰۹xxxxxxxxx باشد.'),
})

const codeSchema = z.object({
  code: z
    .string()
    .trim()
    .transform((value) => toEnglishDigits(value))
    .refine((value) => /^\d{6}$/.test(value), 'کدِ ۶ رقمی را وارد کنید.'),
})

type PhoneInput = z.input<typeof phoneSchema>
type PhoneValues = z.output<typeof phoneSchema>
type CodeInput = z.input<typeof codeSchema>
type CodeValues = z.output<typeof codeSchema>

/**
 * تغییرِ شماره‌ی حساب توسطِ خودِ کاربر (مرحلهٔ ۹ · تصمیمِ کارفرما).
 *
 * گامِ اول: شماره‌ی تازه — سرور پیش از آن رمز را دوباره می‌خواهد (پنجره‌ی
 * «رمزتان را دوباره بزنید» خودکار باز می‌شود). گامِ دوم: کدِ پیامکیِ شماره‌ی تازه.
 * شماره فقط پس از تأییدِ کد عوض می‌شود و دستگاه‌های دیگر خارج می‌شوند.
 */
export function PhoneCard({
  currentPhone,
  onChanged,
}: {
  currentPhone: string | null
  onChanged: () => void
}) {
  const [pendingPhone, setPendingPhone] = useState<string | null>(null)
  const [devCode, setDevCode] = useState<string | null>(null)

  const phoneForm = useForm<PhoneInput, unknown, PhoneValues>({
    resolver: zodResolver(phoneSchema),
  })
  const codeForm = useForm<CodeInput, unknown, CodeValues>({ resolver: zodResolver(codeSchema) })

  async function start(values: PhoneValues) {
    try {
      const result = await api<{ message: string; devCode: string | null }>('/profile/phone', {
        method: 'POST',
        body: values,
      })
      setPendingPhone(values.phone)
      setDevCode(result.devCode)
      toastSuccess(result.message)
    } catch (error) {
      if (error instanceof ApiError && error.fieldError('phone')) {
        phoneForm.setError('phone', { message: error.fieldError('phone') })
        return
      }
      alertError(error, 'شروعِ تغییرِ شماره ممکن نشد.')
    }
  }

  async function confirm(values: CodeValues) {
    try {
      const result = await api<{ message: string }>('/profile/phone/verify', {
        method: 'POST',
        body: values,
      })
      toastSuccess(result.message)
      setPendingPhone(null)
      setDevCode(null)
      phoneForm.reset()
      codeForm.reset()
      onChanged()
    } catch (error) {
      if (error instanceof ApiError && error.fieldError('code')) {
        codeForm.setError('code', { message: error.fieldError('code') })
        return
      }
      alertError(error, 'تأییدِ شماره‌ی تازه ممکن نشد.')
    }
  }

  return (
    <Card
      title="تغییرِ شماره‌ی موبایل"
      subtitle={`شماره‌ی فعلی: ${currentPhone ?? '—'} — شماره فقط پس از تأییدِ کدِ پیامکیِ شماره‌ی تازه عوض می‌شود.`}
      delay={0.1}
    >
      {pendingPhone === null ? (
        <form key="phone" onSubmit={phoneForm.handleSubmit(start)} className="flex flex-col gap-4">
          <TextField
            label="شماره‌ی تازه"
            inputMode="tel"
            autoComplete="tel"
            dir="ltr"
            error={phoneForm.formState.errors.phone?.message}
            {...phoneForm.register('phone')}
          />
          <button
            type="submit"
            disabled={phoneForm.formState.isSubmitting}
            className="flex w-fit items-center gap-1.5 rounded-xl px-5 py-2.5 text-sm font-bold text-white disabled:opacity-60"
            style={{ backgroundColor: 'var(--color-brand-500)' }}
          >
            {phoneForm.formState.isSubmitting ? (
              <Loader2 size={15} className="animate-spin" />
            ) : (
              <Smartphone size={15} />
            )}
            دریافتِ کد
          </button>
        </form>
      ) : (
        <form key="code" onSubmit={codeForm.handleSubmit(confirm)} className="flex flex-col gap-4">
          {/* `key` جدا: بی آن React همان <input> را نگه می‌دارد و شماره در کادرِ کد می‌ماند */}
          <TextField
            label={`کدِ فرستاده‌شده به ${pendingPhone}`}
            inputMode="numeric"
            autoComplete="one-time-code"
            dir="ltr"
            error={codeForm.formState.errors.code?.message}
            {...codeForm.register('code')}
          />
          {devCode && (
            <p className="text-xs" style={{ color: 'var(--text-tertiary)' }}>
              کدِ محیطِ آزمایشی: {devCode}
            </p>
          )}
          <div className="flex flex-wrap items-center gap-2">
            <button
              type="submit"
              disabled={codeForm.formState.isSubmitting}
              className="flex w-fit items-center gap-1.5 rounded-xl px-5 py-2.5 text-sm font-bold text-white disabled:opacity-60"
              style={{ backgroundColor: 'var(--color-brand-500)' }}
            >
              {codeForm.formState.isSubmitting ? (
                <Loader2 size={15} className="animate-spin" />
              ) : (
                <Smartphone size={15} />
              )}
              تأییدِ شماره‌ی تازه
            </button>
            <button
              type="button"
              onClick={() => {
                setPendingPhone(null)
                setDevCode(null)
                codeForm.reset()
              }}
              className="rounded-xl px-4 py-2.5 text-sm font-semibold"
              style={{ color: 'var(--text-secondary)' }}
            >
              انصراف
            </button>
          </div>
        </form>
      )}
    </Card>
  )
}
