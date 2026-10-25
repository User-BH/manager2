import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Loader2, Lock, LogIn, UserRound } from 'lucide-react'
import { AuthScreen } from './components/AuthScreen'
import { RestrictedField } from './components/RestrictedField'
import { SlidePuzzle } from './components/SlidePuzzle'
import { filterAsciiPassword, filterHints, toAsciiDigits } from '@/shared/lib/inputFilters'
import { toastTopError } from '@/shared/lib/alert'
import { api, ApiError } from '@/shared/lib/api'
import type { CurrentUser } from '@/shared/types'
import { useDocumentTitle } from '@/shared/hooks'
import { afterSignIn } from './joinCode'

/**
 * ورود با نام کاربری (مرحلهٔ ۱۳ · فاز ۱؛ پیامِ کارفرما ۲۰۲۶-۱۰-۱۹، بخش ۳) — فقط سوپر ادمین و حساب‌های آزمایشیِ
 * Preview. کاربرِ عادی همیشه با شماره وارد می‌شود (`LoginForm`).
 *
 * صفحه‌ی جدا و تنبل‌بار (`/auth/username`): در بارگذاریِ اولِ صفحه‌ی ورود نیست و باندلِ همه‌ی کاربران را سنگین
 * نمی‌کند — همان الگوی `/auth/recover`. نوعِ شناسه
 * صریح به سرور می‌رود (`identifier_type`)؛ سرور از شکلِ ورودی حدس نمی‌زند و قالب را هم خودش نمی‌سنجد تا پاسخِ
 * «نادرست» برای هر نام کاربری یکی باشد.
 */
const usernameSchema = z.object({
  username: z
    .string()
    .min(1, 'نام کاربری را وارد کنید')
    .regex(/^[a-z][a-z0-9._]{2,31}$/, 'نام کاربری با حرف انگلیسی شروع می‌شود و ۳ تا ۳۲ نویسه است'),
  password: z.string().min(6, 'رمز عبور باید حداقل ۶ کاراکتر باشد'),
})

type UsernameValues = z.infer<typeof usernameSchema>

const WRONG = 'نام کاربری یا رمز عبور نادرست است.'

/** حرفِ کوچکِ لاتین، رقم، نقطه و زیرخط؛ حرفِ بزرگ کوچک می‌شود */
function filterUsername(value: string) {
  const cleaned = toAsciiDigits(value)
    .toLowerCase()
    .replace(/[^a-z0-9._]/g, '')
    .slice(0, 32)
  return { value: cleaned, changed: cleaned !== value }
}

interface LoginResponse {
  otpRequired?: boolean
  phone?: string
  dev_code?: string | null
  user?: CurrentUser
}

export default function UsernameLoginPage() {
  useDocumentTitle('ورود با نام کاربری')
  const navigate = useNavigate()
  const [submitting, setSubmitting] = useState(false)
  const [human, setHuman] = useState(false)
  const [credError, setCredError] = useState(false)
  const [puzzleReset, setPuzzleReset] = useState(0)

  const {
    control,
    handleSubmit,
    formState: { errors },
  } = useForm<UsernameValues>({
    resolver: zodResolver(usernameSchema),
    defaultValues: { username: '', password: '' },
  })

  async function onSubmit(values: UsernameValues) {
    if (!human) {
      toastTopError('لطفاً ابتدا پازل امنیتی را کامل کنید.')
      return
    }

    setSubmitting(true)
    setCredError(false)

    try {
      const data = await api<LoginResponse>('/login', {
        method: 'POST',
        body: { identifier_type: 'username', ...values },
      })

      if (data.user) {
        window.location.replace(afterSignIn())
        return
      }

      // سوپر ادمین کدِ پیامکی می‌گیرد؛ سرور فقط شماره‌ی پوشیده را برمی‌گرداند
      if (data.otpRequired) {
        void navigate('/auth/verify', {
          replace: true,
          state: { phone: data.phone, devCode: data.dev_code ?? null },
        })
      }
    } catch (error) {
      setHuman(false)
      setPuzzleReset((n) => n + 1)

      if (error instanceof ApiError) {
        const message =
          error.fieldError('username') ?? error.fieldError('password') ?? error.message
        if (message === WRONG) setCredError(true)
        toastTopError(message)
      } else {
        toastTopError('ارتباط با سرور برقرار نشد.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <AuthScreen title="ورود با نام کاربری" subtitle="ویژه‌ی مدیریتِ سامانه و حساب‌های آزمایشی">
      <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-3">
        <RestrictedField
          control={control}
          name="username"
          label="نام کاربری"
          icon={UserRound}
          placeholder="نام کاربری"
          dir="ltr"
          autoComplete="username"
          error={errors.username?.message}
          invalid={credError}
          onUserInput={() => setCredError(false)}
          filter={filterUsername}
          hint="فقط حروف کوچک انگلیسی، عدد، نقطه و زیرخط."
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

        <div
          className="rounded-2xl border p-2.5"
          style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
        >
          <SlidePuzzle onSolved={setHuman} resetSignal={puzzleReset} />
        </div>

        <Link
          to="/auth"
          replace
          className="self-start text-xs font-medium"
          style={{ color: 'var(--text-secondary)' }}
        >
          ورود با شماره موبایل
        </Link>

        <button
          type="submit"
          disabled={submitting}
          className="mt-2 flex items-center justify-center gap-2 rounded-xl py-3.5 text-sm font-bold text-white shadow-sm transition-transform duration-200 hover:scale-[1.02] disabled:opacity-70 disabled:hover:scale-100"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          {submitting ? <Loader2 size={17} className="animate-spin" /> : <LogIn size={17} />}
          {submitting ? 'در حال ورود...' : 'ورود به پنل'}
        </button>
      </form>
    </AuthScreen>
  )
}
