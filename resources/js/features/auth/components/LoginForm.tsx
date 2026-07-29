import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { motion } from 'framer-motion'
import { Loader2, Lock, LogIn, Phone } from 'lucide-react'
import { RestrictedField } from './RestrictedField'
import { SlidePuzzle } from './SlidePuzzle'
import { loginSchema, type LoginFormValues } from '../schemas/loginSchema'
import { filterAsciiPassword, filterHints, filterMobile } from '@/shared/lib/inputFilters'
import { toastTopError } from '@/shared/lib/alert'
import { forgetRememberedPhone, loadRememberedPhone, saveRememberedPhone } from '@/shared/lib/rememberMe'
import { api, ApiError } from '@/shared/lib/api'
import type { CurrentUser } from '@/shared/types'

interface LoginResponse {
  otpRequired?: boolean
  phone?: string
  dev_code?: string | null
  user?: CurrentUser
}

/** پیامِ سرور برای «شماره یا رمزِ نادرست»؛ این حالت بوردر قرمز + توست می‌گیرد. */
const WRONG_CREDENTIALS = 'شماره تلفن یا رمز عبور نادرست است.'

export function LoginForm() {
  const navigate = useNavigate()
  const [submitting, setSubmitting] = useState(false)
  const [human, setHuman] = useState(false)
  // ورودِ ناموفق: هر دو اینپوت قرمز شوند (پیام در توست، نه زیر اینپوت)
  const [credError, setCredError] = useState(false)
  // با هر افزایش، پازل با تصویری تازه از نو ساخته می‌شود
  const [puzzleReset, setPuzzleReset] = useState(0)

  const {
    control,
    register,
    handleSubmit,
    setValue,
    formState: { errors },
  } = useForm<LoginFormValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { phone: '', password: '', remember: false },
  })

  /*
   * اگر دفعه‌ی قبل «مرا به خاطر بسپار» زده بود، شماره‌اش را برمی‌گردانیم.
   * این فقط راحتیِ پرکردن فرم است؛ احرازِ واقعی با کوکیِ دستگاه مورداعتماد
   * است که سرور صادر می‌کند و همان ۱۰ روز اعتبار دارد.
   */
  useEffect(() => {
    const remembered = loadRememberedPhone()
    if (remembered) {
      setValue('phone', remembered)
      setValue('remember', true)
    }
  }, [setValue])

  async function onSubmit(values: LoginFormValues) {
    if (!human) {
      // توستِ بالا-وسط: هرجای فرم که کاربر باشد، اخطارِ نبودِ پازل را می‌بیند.
      toastTopError('لطفاً ابتدا پازل امنیتی را کامل کنید.')
      return
    }

    setSubmitting(true)
    setCredError(false)

    try {
      const data = await api<LoginResponse>('/login', { method: 'POST', body: values })

      // تیک «مرا به خاطر بسپار» واقعاً اثر دارد: شماره تا ۱۰ روز نگه داشته
      // می‌شود و سرور هم دستگاه را مورداعتماد می‌کند.
      if (values.remember) saveRememberedPhone(values.phone)
      else forgetRememberedPhone()

      // دستگاه مورداعتماد: بدون مرحله‌ی دوم مستقیم وارد شد.
      // داشبورد یک سندِ جداست، پس با ناوبریِ واقعیِ مرورگر می‌رویم؛ نشستِ
      // سمت سرور آنجا دوباره خوانده می‌شود.
      if (data.user) {
        window.location.assign('/dashboard')
        return
      }

      // مرحله‌ی دوم: به صفحه‌ی تایید کد می‌رویم (داخلِ همین island است)
      if (data.otpRequired) {
        navigate('/auth/verify', {
          state: { phone: data.phone, devCode: data.dev_code ?? null },
        })
      }
    } catch (error) {
      /*
       * هر ورودِ ناموفق یعنی باید دوباره تلاش شود؛ پس پازل را از نو می‌سازیم و
       * `human` را صفر می‌کنیم تا کاربر دوباره حلش کند. این جلوی «یک‌بار حل،
       * چند بار تلاش» را هم می‌گیرد.
       */
      setHuman(false)
      setPuzzleReset((n) => n + 1)

      if (error instanceof ApiError) {
        const phoneError = error.fieldError('phone')
        const passwordError = error.fieldError('password')

        if (phoneError === WRONG_CREDENTIALS) {
          // شماره/رمز نادرست: هر دو اینپوت قرمز، پیام در توستِ بالا-وسط
          setCredError(true)
          toastTopError(WRONG_CREDENTIALS)
        } else {
          // سایر خطاهای سرور (حساب غیرفعال، ارسال کد ناموفق و…) هم در توست
          toastTopError(phoneError ?? passwordError ?? error.message)
        }
      } else {
        toastTopError('ارتباط با سرور برقرار نشد.')
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
      className="flex flex-col gap-3"
    >
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
        invalid={credError}
        onUserInput={() => setCredError(false)}
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
        invalid={credError}
        onUserInput={() => setCredError(false)}
        filter={filterAsciiPassword}
        hint={filterHints.asciiPassword}
      />

      {/* پازل امنیتی «ربات نیستم» */}
      <div
        className="rounded-2xl border p-2.5"
        style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
      >
        <SlidePuzzle onSolved={setHuman} resetSignal={puzzleReset} />
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
