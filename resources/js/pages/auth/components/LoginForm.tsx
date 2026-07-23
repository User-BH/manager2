import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { motion } from 'framer-motion'
import { AlertCircle, Loader2, Lock, LogIn, Phone } from 'lucide-react'
import { RestrictedField } from './RestrictedField'
import { SlidePuzzle } from './SlidePuzzle'
import { loginSchema, type LoginFormValues } from '../schemas/loginSchema'
import { filterAsciiPassword, filterHints, filterMobile } from '@/lib/inputFilters'
import { useAuth } from '@/context/AuthContext'
import { api, ApiError } from '@/lib/api'
import type { CurrentUser } from '@/types'

interface LoginResponse {
  otpRequired?: boolean
  phone?: string
  dev_code?: string | null
  user?: CurrentUser
}

export function LoginForm() {
  const navigate = useNavigate()
  const { setUser } = useAuth()
  const [submitting, setSubmitting] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const [human, setHuman] = useState(false)

  const {
    control,
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<LoginFormValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { phone: '', password: '', remember: false },
  })

  async function onSubmit(values: LoginFormValues) {
    if (!human) {
      setFormError('لطفاً پازل امنیتی را کامل کنید.')
      return
    }

    setSubmitting(true)
    setFormError(null)

    try {
      const data = await api<LoginResponse>('/login', { method: 'POST', body: values })

      // دستگاه مورداعتماد: بدون مرحله‌ی دوم مستقیم وارد شد
      if (data.user) {
        setUser(data.user)
        navigate('/dashboard', { replace: true })
        return
      }

      // مرحله‌ی دوم: به صفحه‌ی تایید کد می‌رویم
      if (data.otpRequired) {
        navigate('/auth/verify', {
          state: { phone: data.phone, devCode: data.dev_code ?? null },
        })
      }
    } catch (error) {
      if (error instanceof ApiError) {
        const phoneError = error.fieldError('phone')
        const passwordError = error.fieldError('password')
        if (phoneError) setError('phone', { message: phoneError })
        if (passwordError) setError('password', { message: passwordError })
        if (!phoneError && !passwordError) setFormError(error.message)
      } else {
        setFormError('ارتباط با سرور برقرار نشد.')
      }
    } finally {
      setSubmitting(false)
    }
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
        name="phone"
        label="شماره موبایل"
        icon={Phone}
        placeholder="۰۹xxxxxxxxx"
        inputMode="numeric"
        dir="ltr"
        autoComplete="username"
        error={errors.phone?.message}
        filter={filterMobile}
        hint={filterHints.mobile}
      />

      <RestrictedField
        control={control}
        name="password"
        label="رمز عبور"
        icon={Lock}
        type="password"
        placeholder="رمز عبور خود را وارد کنید"
        dir="ltr"
        autoComplete="current-password"
        error={errors.password?.message}
        filter={filterAsciiPassword}
        hint={filterHints.asciiPassword}
      />

      {/* پازل امنیتی «ربات نیستم» */}
      <div
        className="rounded-2xl border p-3"
        style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
      >
        <SlidePuzzle onSolved={setHuman} />
      </div>

      <div className="flex items-center justify-between">
        <label className="flex items-center gap-2 text-xs" style={{ color: 'var(--text-secondary)' }}>
          <input type="checkbox" className="h-4 w-4 rounded" {...register('remember')} />
          مرا به‌خاطر بسپار
        </label>
        <Link to="/auth/forgot" className="text-xs font-medium" style={{ color: 'var(--color-brand-600)' }}>
          رمز عبور را فراموش کرده‌اید؟
        </Link>
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
            در حال ورود...
          </>
        ) : (
          <>
            <LogIn size={17} />
            ورود به پنل
          </>
        )}
      </button>
    </motion.form>
  )
}
