import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useForm, useWatch } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { motion } from 'framer-motion'
import {
  AlertCircle,
  ArrowRight,
  Loader2,
  Lock,
  Phone,
  ShieldCheck,
  User,
  UserPlus,
} from 'lucide-react'
import { RestrictedField } from './RestrictedField'
import { PasswordStrength } from './PasswordStrength'
import { OtpBoxes } from './OtpBoxes'
import { registerSchema, type RegisterFormValues } from '../schemas/registerSchema'
import {
  filterAsciiPassword,
  filterHints,
  filterMobile,
  filterPersianLetters,
} from '@/shared/lib/inputFilters'
import { toastTopSuccess } from '@/shared/lib/alert'
import { api, ApiError } from '@/shared/lib/api'

export function RegisterForm({ onRegistered }: { onRegistered?: () => void }) {
  const [submitting, setSubmitting] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)

  // گامِ دوم: تاییدِ کدِ پیامکی. حساب تنها پس از این ساخته می‌شود، پس ثبت‌نامِ
  // نیمه‌کاره هیچ رکوردی باقی نمی‌گذارد.
  const [step, setStep] = useState<'form' | 'otp'>('form')
  const [phone, setPhone] = useState('')
  const [devCode, setDevCode] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const [otpError, setOtpError] = useState<string | null>(null)
  const [verifying, setVerifying] = useState(false)

  const {
    control,
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<RegisterFormValues>({
    resolver: zodResolver(registerSchema),
    defaultValues: {
      fullName: '',
      phone: '',
      password: '',
      confirmPassword: '',
      acceptTerms: false,
    },
  })

  // برای سنجه‌ی قدرت، مقدار زنده‌ی رمز لازم است
  const passwordValue = useWatch({ control, name: 'password' }) ?? ''

  async function onSubmit(values: RegisterFormValues) {
    setSubmitting(true)
    setFormError(null)

    try {
      const data = await api<{ otpRequired?: boolean; phone?: string; dev_code?: string | null }>(
        '/register',
        {
          method: 'POST',
          body: {
            name: values.fullName,
            phone: values.phone,
            password: values.password,
            password_confirmation: values.confirmPassword,
            // پذیرش قوانین سمت سرور هم ثبت می‌شود، نه فقط تیکِ مرورگر
            accept_terms: values.acceptTerms,
          },
        },
      )

      // حساب هنوز ساخته نشده؛ فقط کد فرستاده شده. به گامِ تاییدِ کد می‌رویم.
      if (data.otpRequired) {
        setPhone(data.phone ?? values.phone)
        setDevCode(data.dev_code ?? null)
        setCode('')
        setStep('otp')
      }
    } catch (error) {
      if (error instanceof ApiError) {
        const map: Record<string, keyof RegisterFormValues> = {
          name: 'fullName',
          phone: 'phone',
          password: 'password',
          accept_terms: 'acceptTerms',
        }

        let handled = false
        for (const [apiField, formField] of Object.entries(map)) {
          const message = error.fieldError(apiField)
          if (message) {
            setError(formField, { message })
            handled = true
          }
        }

        if (!handled) setFormError(error.message)
      } else {
        setFormError('ارتباط با سرور برقرار نشد.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  /** با کاملِ‌شدنِ شش رقم خودکار صدا زده می‌شود؛ حساب همین‌جا ساخته می‌شود. */
  async function verify(value: string) {
    if (verifying) return
    setVerifying(true)
    setOtpError(null)

    try {
      await api('/register/verify', { method: 'POST', body: { code: value } })
      toastTopSuccess('ثبت‌نام شما کامل شد. برای استفاده از خدمات، وارد حساب خود شوید.')
      onRegistered?.()
    } catch (err) {
      setOtpError(
        err instanceof ApiError
          ? (err.fieldError('code') ?? err.message)
          : 'ارتباط با سرور برقرار نشد.',
      )
      setCode('')
    } finally {
      setVerifying(false)
    }
  }

  // گامِ دوم: کدِ پیامکی
  if (step === 'otp') {
    return (
      <motion.div
        initial={{ opacity: 0, y: 10 }}
        animate={{ opacity: 1, y: 0 }}
        className="flex flex-col gap-5"
        dir="rtl"
      >
        <div className="flex flex-col items-center gap-2 text-center">
          <span
            className="flex h-11 w-11 items-center justify-center rounded-2xl"
            style={{
              backgroundColor: 'color-mix(in srgb, var(--color-brand-500) 14%, transparent)',
            }}
          >
            <ShieldCheck size={22} style={{ color: 'var(--color-brand-600)' }} />
          </span>
          <p className="text-[13px]" style={{ color: 'var(--text-secondary)' }}>
            کد شش‌رقمی به شماره{' '}
            <span dir="ltr" className="font-bold">
              {phone}
            </span>{' '}
            پیامک شد.
          </p>
        </div>

        <OtpBoxes
          value={code}
          onChange={setCode}
          onComplete={verify}
          disabled={verifying}
          hasError={Boolean(otpError)}
          autoFocus
        />

        {verifying && (
          <div
            className="flex items-center justify-center gap-2 text-[13px]"
            style={{ color: 'var(--text-secondary)' }}
          >
            <Loader2 size={15} className="animate-spin" />
            در حال تایید…
          </div>
        )}
        {otpError && (
          <p className="text-center text-[12.5px]" style={{ color: 'var(--color-danger)' }}>
            {otpError}
          </p>
        )}
        {devCode && (
          <p className="text-center text-[11.5px]" style={{ color: 'var(--text-tertiary)' }}>
            کد تست: <span className="font-mono font-bold">{devCode}</span>
          </p>
        )}

        <button
          type="button"
          onClick={() => {
            setStep('form')
            setOtpError(null)
          }}
          className="flex items-center justify-center gap-1.5 text-xs font-medium"
          style={{ color: 'var(--text-tertiary)' }}
        >
          <ArrowRight size={14} />
          اصلاح اطلاعات
        </button>
      </motion.div>
    )
  }

  return (
    <motion.form
      onSubmit={handleSubmit(onSubmit)}
      initial={{ opacity: 0 }}
      animate={{ opacity: 1 }}
      exit={{ opacity: 0 }}
      transition={{ duration: 0.25 }}
      className="flex flex-col gap-4"
    >
      {formError && (
        <div
          className="flex items-center gap-2 rounded-xl px-3.5 py-2.5 text-xs"
          style={{
            backgroundColor: 'color-mix(in srgb, var(--color-danger) 12%, transparent)',
            color: 'var(--color-danger)',
          }}
        >
          <AlertCircle size={15} className="shrink-0" />
          {formError}
        </div>
      )}

      <RestrictedField
        control={control}
        name="fullName"
        label="نام و نام خانوادگی"
        icon={User}
        placeholder="مثلاً علی محمدی"
        error={errors.fullName?.message}
        filter={filterPersianLetters}
        hint={filterHints.persianLetters}
      />

      <RestrictedField
        control={control}
        name="phone"
        label="شماره موبایل"
        icon={Phone}
        placeholder="۰۹xxxxxxxxx"
        inputMode="numeric"
        dir="ltr"
        error={errors.phone?.message}
        filter={filterMobile}
        hint={filterHints.mobile}
      />

      <div>
        <RestrictedField
          control={control}
          name="password"
          label="رمز عبور"
          icon={Lock}
          type="password"
          placeholder="حداقل ۸ نویسه"
          dir="ltr"
          error={errors.password?.message}
          filter={filterAsciiPassword}
          hint={filterHints.asciiPassword}
        />
        <PasswordStrength value={passwordValue} />
      </div>

      <RestrictedField
        control={control}
        name="confirmPassword"
        label="تکرار رمز عبور"
        icon={Lock}
        type="password"
        placeholder="تکرار رمز عبور"
        dir="ltr"
        error={errors.confirmPassword?.message}
        filter={filterAsciiPassword}
        hint={filterHints.asciiPassword}
      />

      <div className="flex flex-col gap-1">
        <label
          className="flex items-start gap-2 text-xs"
          style={{ color: 'var(--text-secondary)' }}
        >
          <input type="checkbox" className="mt-0.5 h-4 w-4 rounded" {...register('acceptTerms')} />
          <span>
            {/* لینک قوانین: کلیک به صفحه‌ی پشتیبانی، بخش قوانین */}
            <Link
              to="/support?topic=terms"
              target="_blank"
              className="font-semibold underline"
              style={{ color: 'var(--color-brand-600)' }}
            >
              قوانین و مقررات استفاده از پنل
            </Link>{' '}
            را مطالعه کرده‌ام و می‌پذیرم.
          </span>
        </label>
        {errors.acceptTerms && (
          <p className="text-xs" style={{ color: 'var(--color-danger)' }}>
            {errors.acceptTerms.message}
          </p>
        )}
      </div>

      <button
        type="submit"
        disabled={submitting}
        className="mt-2 flex items-center justify-center gap-2 rounded-xl py-3.5 text-sm font-bold text-white shadow-sm transition-transform duration-200 hover:scale-[1.02] disabled:opacity-70 disabled:hover:scale-100"
        style={{ backgroundColor: 'var(--color-brand-500)' }}
      >
        {submitting ? (
          <>
            <Loader2 size={17} className="animate-spin" />
            در حال ارسال کد...
          </>
        ) : (
          <>
            <UserPlus size={17} />
            ادامه و دریافت کد تایید
          </>
        )}
      </button>
    </motion.form>
  )
}
