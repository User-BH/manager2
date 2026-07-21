import { useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { motion } from 'framer-motion'
import { Lock, Phone, LogIn, Loader2, AlertCircle } from 'lucide-react'
import { FormField } from './FormField'
import { loginSchema, type LoginFormValues } from '../schemas/loginSchema'
import { useAuth } from '@/context/AuthContext'
import { api, ApiError } from '@/lib/api'
import type { CurrentUser } from '@/types'

export function LoginForm() {
  const navigate = useNavigate()
  const location = useLocation()
  const { setUser } = useAuth()
  const [submitting, setSubmitting] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<LoginFormValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { phone: '', password: '', remember: false },
  })

  async function onSubmit(values: LoginFormValues) {
    setSubmitting(true)
    setFormError(null)

    try {
      const { user } = await api<{ user: CurrentUser }>('/login', {
        method: 'POST',
        body: values,
      })

      setUser(user)

      // بازگشت به همان مسیری که کاربر قصد بازدیدش را داشت
      const from = (location.state as { from?: { pathname?: string } } | null)?.from?.pathname
      navigate(from ?? '/dashboard', { replace: true })
    } catch (error) {
      if (error instanceof ApiError) {
        // خطاهای اعتبارسنجی لاراول زیر همان فیلد نشان داده می‌شوند
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
      initial={{ opacity: 0, x: 12 }}
      animate={{ opacity: 1, x: 0 }}
      transition={{ duration: 0.3 }}
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

      <FormField
        label="شماره موبایل"
        icon={Phone}
        placeholder="۰۹xxxxxxxxx"
        inputMode="numeric"
        autoComplete="username"
        error={errors.phone?.message}
        {...register('phone')}
      />

      <FormField
        label="رمز عبور"
        icon={Lock}
        type="password"
        placeholder="رمز عبور خود را وارد کنید"
        autoComplete="current-password"
        error={errors.password?.message}
        {...register('password')}
      />

      <div className="flex items-center justify-between">
        <label className="flex items-center gap-2 text-xs" style={{ color: 'var(--text-secondary)' }}>
          <input type="checkbox" className="h-4 w-4 rounded" {...register('remember')} />
          مرا به‌خاطر بسپار
        </label>
        <button type="button" className="text-xs font-medium" style={{ color: 'var(--color-brand-600)' }}>
          رمز عبور را فراموش کرده‌اید؟
        </button>
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
