import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link } from 'react-router-dom'
import { zodResolver } from '@hookform/resolvers/zod'
import { KeyRound, Loader2, Phone, ShieldCheck } from 'lucide-react'
import { z } from 'zod'
import { AuthScreen } from './components/AuthScreen'
import { RestrictedField } from './components/RestrictedField'
import { TextAreaField, TextField } from '@/shared/ui/Field'
import { filterMobile, filterOtp } from '@/shared/lib/inputFilters'
import { api, ApiError } from '@/shared/lib/api'
import { toEnglishDigits } from '@/shared/lib/format'
import { useDocumentTitle } from '@/shared/hooks'

// همان الگوی `MOBILE_PATTERN`؛ تکرار شد تا ماژولِ اعتبارسنجی چانکِ تازه‌ای به نشستِ ورود نیاورد
const MOBILE = /^09\d{9}$/

const phonesSchema = z
  .object({
    old_phone: z.string().regex(MOBILE, 'شماره‌ی قبلی باید به شکل ۰۹xxxxxxxxx باشد.'),
    new_phone: z.string().regex(MOBILE, 'شماره‌ی تازه باید به شکل ۰۹xxxxxxxxx باشد.'),
  })
  .refine((values) => values.old_phone !== values.new_phone, {
    message: 'شماره‌ی تازه باید با شماره‌ی قبلی فرق کند.',
    path: ['new_phone'],
  })

const detailsSchema = z.object({
  code: z.string().regex(/^\d{6}$/, 'کدِ ۶ رقمی را وارد کنید.'),
  national_id: z
    .string()
    .trim()
    .transform((value) => toEnglishDigits(value))
    .refine((value) => value === '' || /^\d{10}$/.test(value), 'کد ملی باید ۱۰ رقم باشد.'),
  explanation: z
    .string()
    .trim()
    .min(20, 'شرح را کامل‌تر بنویسید (دست‌کم ۲۰ نویسه).')
    .max(1000, 'شرح حداکثر ۱۰۰۰ نویسه است.'),
})

type PhonesValues = z.infer<typeof phonesSchema>
type DetailsInput = z.input<typeof detailsSchema>
type DetailsValues = z.output<typeof detailsSchema>

/**
 * بازیابیِ حساب وقتی شماره‌ی قبلی در دسترس نیست (مرحلهٔ ۹ · تصمیمِ کارفرما).
 *
 * این صفحه **دسترسی نمی‌دهد**: فقط مالکیتِ شماره‌ی تازه را با کدِ پیامکی ثابت و
 * درخواست را برای بررسیِ پشتیبانی ثبت می‌کند. پاسخ برای هر شماره‌ی قبلی یکسان است.
 */
export function RecoverAccountPage() {
  useDocumentTitle('بازیابی حساب')

  const [step, setStep] = useState<'phones' | 'details' | 'done'>('phones')
  const [phones, setPhones] = useState<PhonesValues | null>(null)
  const [devCode, setDevCode] = useState<string | null>(null)
  const [doneMessage, setDoneMessage] = useState('')

  const phonesForm = useForm<PhonesValues>({
    resolver: zodResolver(phonesSchema),
    defaultValues: { old_phone: '', new_phone: '' },
  })
  const detailsForm = useForm<DetailsInput, unknown, DetailsValues>({
    resolver: zodResolver(detailsSchema),
    defaultValues: { code: '', national_id: '', explanation: '' },
  })

  async function sendCode(values: PhonesValues) {
    try {
      const data = await api<{ devCode: string | null }>('/account-recovery/code', {
        method: 'POST',
        body: { new_phone: values.new_phone },
      })
      setPhones(values)
      setDevCode(data.devCode)
      setStep('details')
    } catch (error) {
      phonesForm.setError('new_phone', {
        message:
          error instanceof ApiError
            ? (error.fieldError('new_phone') ?? error.message)
            : 'ارتباط با سرور برقرار نشد.',
      })
    }
  }

  async function submit(values: DetailsValues) {
    if (!phones) return
    try {
      const data = await api<{ message: string }>('/account-recovery', {
        method: 'POST',
        body: { ...phones, ...values, national_id: values.national_id || null },
      })
      setDoneMessage(data.message)
      setStep('done')
    } catch (error) {
      if (error instanceof ApiError) {
        for (const field of ['code', 'national_id', 'explanation'] as const) {
          const message = error.fieldError(field)
          if (message) detailsForm.setError(field, { message })
        }
        if (!error.fieldError('code') && !error.fieldError('explanation')) {
          detailsForm.setError('code', { message: error.message })
        }
        return
      }
      detailsForm.setError('code', { message: 'ارتباط با سرور برقرار نشد.' })
    }
  }

  const subtitles = {
    phones: 'به شماره‌ی قبلی دسترسی ندارید؟ شماره‌ی تازه را ثابت کنید تا پشتیبانی بررسی کند.',
    details: 'کدِ پیامکِ شماره‌ی تازه و توضیحی برای بررسیِ مالکیتِ حساب.',
    done: 'درخواست ثبت شد.',
  }

  return (
    <AuthScreen title="بازیابی حساب" subtitle={subtitles[step]}>
      {step === 'phones' && (
        <form
          key="phones"
          onSubmit={phonesForm.handleSubmit(sendCode)}
          className="flex flex-col gap-4"
          noValidate
        >
          <RestrictedField
            control={phonesForm.control}
            name="old_phone"
            label="شماره‌ی قبلیِ حساب"
            icon={Phone}
            placeholder="۰۹xxxxxxxxx"
            inputMode="numeric"
            dir="ltr"
            filter={filterMobile}
            hint="فقط رقم وارد کنید"
            error={phonesForm.formState.errors.old_phone?.message}
          />
          <RestrictedField
            control={phonesForm.control}
            name="new_phone"
            label="شماره‌ی تازه"
            icon={Phone}
            placeholder="۰۹xxxxxxxxx"
            inputMode="numeric"
            dir="ltr"
            filter={filterMobile}
            hint="فقط رقم وارد کنید"
            error={phonesForm.formState.errors.new_phone?.message}
          />
          <button
            type="submit"
            disabled={phonesForm.formState.isSubmitting}
            className="mt-1 flex items-center justify-center gap-2 rounded-xl py-3.5 text-sm font-bold text-white disabled:opacity-60"
            style={{ backgroundColor: 'var(--color-brand-500)' }}
          >
            {phonesForm.formState.isSubmitting ? (
              <Loader2 size={17} className="animate-spin" />
            ) : (
              <KeyRound size={17} />
            )}
            ارسالِ کد به شماره‌ی تازه
          </button>
          <Link
            to="/auth/forgot"
            className="text-center text-sm font-semibold"
            style={{ color: 'var(--color-brand-600)' }}
          >
            به شماره‌ی قبلی دسترسی دارید؟ فراموشیِ رمز
          </Link>
        </form>
      )}

      {step === 'details' && (
        <form
          key="details"
          onSubmit={detailsForm.handleSubmit(submit)}
          className="flex flex-col gap-4"
          noValidate
        >
          <RestrictedField
            control={detailsForm.control}
            name="code"
            label={`کدِ فرستاده‌شده به ${phones?.new_phone ?? ''}`}
            icon={ShieldCheck}
            placeholder="۶ رقم"
            inputMode="numeric"
            dir="ltr"
            autoComplete="one-time-code"
            filter={(value) => filterOtp(value)}
            hint="فقط رقم وارد کنید"
            error={detailsForm.formState.errors.code?.message}
          />
          {devCode && (
            <p className="text-center text-xs" style={{ color: 'var(--text-tertiary)' }}>
              کد تست: <span className="font-mono font-bold">{devCode}</span>
            </p>
          )}
          <TextField
            label="کد ملی (اختیاری، برای سنجشِ مالکیت)"
            inputMode="numeric"
            dir="ltr"
            error={detailsForm.formState.errors.national_id?.message}
            {...detailsForm.register('national_id')}
          />
          <TextAreaField
            label="چه شد و چطور می‌توان مالکیتِ حساب را سنجید؟"
            rows={4}
            error={detailsForm.formState.errors.explanation?.message}
            {...detailsForm.register('explanation')}
          />
          <button
            type="submit"
            disabled={detailsForm.formState.isSubmitting}
            className="mt-1 flex items-center justify-center gap-2 rounded-xl py-3.5 text-sm font-bold text-white disabled:opacity-60"
            style={{ backgroundColor: 'var(--color-brand-500)' }}
          >
            {detailsForm.formState.isSubmitting ? (
              <Loader2 size={17} className="animate-spin" />
            ) : (
              <ShieldCheck size={17} />
            )}
            ثبتِ درخواستِ بازیابی
          </button>
        </form>
      )}

      {step === 'done' && (
        <div className="flex flex-col gap-4 text-center">
          <p className="text-sm leading-7" style={{ color: 'var(--text-secondary)' }}>
            {doneMessage}
          </p>
          <Link
            to="/auth"
            className="text-sm font-semibold"
            style={{ color: 'var(--color-brand-600)' }}
          >
            بازگشت به ورود
          </Link>
        </div>
      )}
    </AuthScreen>
  )
}
