import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useForm, useWatch } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { motion } from 'framer-motion'
import { AlertCircle, CheckCircle2, Loader2, Lock, Phone, User, UserPlus } from 'lucide-react'
import { RestrictedField } from './RestrictedField'
import { PasswordStrength } from './PasswordStrength'
import { registerSchema, type RegisterFormValues } from '../schemas/registerSchema'
import { filterAsciiPassword, filterHints, filterMobile, filterPersianLetters } from '@/lib/inputFilters'
import { api, ApiError } from '@/lib/api'

export function RegisterForm({ onRegistered }: { onRegistered?: () => void }) {
  const [submitting, setSubmitting] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const [done, setDone] = useState<string | null>(null)

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
      const { message } = await api<{ message: string }>('/register', {
        method: 'POST',
        body: {
          name: values.fullName,
          phone: values.phone,
          password: values.password,
          password_confirmation: values.confirmPassword,
          // پذیرش قوانین سمت سرور هم ثبت می‌شود، نه فقط تیکِ مرورگر
          accept_terms: values.acceptTerms,
        },
      })

      // حساب ساخته می‌شود اما غیرفعال است تا مدیر مجتمع تاییدش کند.
      setDone(message)
      onRegistered?.()
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

  if (done) {
    return (
      <motion.div
        initial={{ opacity: 0, scale: 0.96 }}
        animate={{ opacity: 1, scale: 1 }}
        className="flex flex-col items-center gap-3 rounded-2xl border p-8 text-center"
        style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-sunken)' }}
      >
        <CheckCircle2 size={40} style={{ color: 'var(--color-brand-500)' }} />
        <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
          ثبت‌نام انجام شد
        </p>
        <p className="text-[13px] leading-6" style={{ color: 'var(--text-secondary)' }}>
          {done}
        </p>
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
        <label className="flex items-start gap-2 text-xs" style={{ color: 'var(--text-secondary)' }}>
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
            در حال ثبت‌نام...
          </>
        ) : (
          <>
            <UserPlus size={17} />
            ساخت حساب مجتمع
          </>
        )}
      </button>
    </motion.form>
  )
}
