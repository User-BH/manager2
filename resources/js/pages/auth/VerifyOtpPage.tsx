import { useEffect, useState } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'
import { Loader2, ShieldCheck } from 'lucide-react'
import { AuthScreen } from './components/AuthScreen'
import { OtpBoxes } from './components/OtpBoxes'
import { api, ApiError } from '@/lib/api'
import { useAuth } from '@/context/AuthContext'
import { useDocumentTitle } from '@/hooks'
import type { CurrentUser } from '@/types'

interface VerifyState {
  phone?: string
  devCode?: string | null
}

const RESEND_SECONDS = 60

/**
 * صفحه‌ی تایید کد ورود (مرحله‌ی دوم).
 *
 * پس از رمز درست، کاربر اینجا می‌آید؛ کد شش‌رقمی پیامک‌شده را وارد می‌کند و به
 * محضِ کامل‌شدن، بدون فشار دکمه، ورود انجام و به داشبورد می‌رود.
 */
export function VerifyOtpPage() {
  const navigate = useNavigate()
  const location = useLocation()
  const { setUser, isAuthenticated } = useAuth()
  const state = (location.state as VerifyState | null) ?? {}

  const [code, setCode] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [cooldown, setCooldown] = useState(RESEND_SECONDS)
  const [devCode, setDevCode] = useState<string | null>(state.devCode ?? null)

  useDocumentTitle('تایید کد ورود')

  // شمارش معکوس ارسال مجدد
  useEffect(() => {
    if (cooldown <= 0) return
    const t = setTimeout(() => setCooldown((c) => c - 1), 1000)
    return () => clearTimeout(t)
  }, [cooldown])

  // اگر مستقیم و بدون طی‌کردن گام رمز به اینجا آمد، به ورود برگردد
  if (!state.phone && !isAuthenticated) {
    return <Navigate to="/auth" replace />
  }
  if (isAuthenticated) {
    return <Navigate to="/dashboard" replace />
  }

  async function submit(value: string) {
    if (submitting) return
    setSubmitting(true)
    setError(null)

    try {
      const { user } = await api<{ user: CurrentUser }>('/login/verify', {
        method: 'POST',
        body: { code: value },
      })

      setUser(user)
      navigate('/dashboard', { replace: true })
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        setError(err.fieldError('code') ?? err.message)
      } else {
        setError('ارتباط با سرور برقرار نشد.')
      }
      setCode('')
    } finally {
      setSubmitting(false)
    }
  }

  async function resend() {
    setError(null)
    try {
      const data = await api<{ dev_code?: string | null }>('/login/resend', { method: 'POST' })
      setDevCode(data.dev_code ?? null)
      setCooldown(RESEND_SECONDS)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'ارسال مجدد کد ممکن نشد.')
    }
  }

  return (
    <AuthScreen
      title="کد ورود را وارد کنید"
      subtitle={
        state.phone
          ? `کد شش‌رقمی به شماره ${state.phone} پیامک شد.`
          : 'کد شش‌رقمیِ پیامک‌شده را وارد کنید.'
      }
    >
      <div className="flex flex-col gap-5">
        <span
          className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl"
          style={{ backgroundColor: 'color-mix(in srgb, var(--color-brand-500) 14%, transparent)' }}
        >
          <ShieldCheck size={24} style={{ color: 'var(--color-brand-600)' }} />
        </span>

        <OtpBoxes
          value={code}
          onChange={setCode}
          onComplete={submit}
          disabled={submitting}
          hasError={Boolean(error)}
          autoFocus
        />

        {submitting && (
          <div className="flex items-center justify-center gap-2 text-[13px]" style={{ color: 'var(--text-secondary)' }}>
            <Loader2 size={15} className="animate-spin" />
            در حال تایید…
          </div>
        )}

        {error && (
          <p className="text-center text-[12.5px]" style={{ color: 'var(--color-danger)' }}>
            {error}
          </p>
        )}

        {/* در حالت تست، کد روی صفحه نشان داده می‌شود */}
        {devCode && (
          <p className="text-center text-[11.5px]" style={{ color: 'var(--text-tertiary)' }}>
            کد تست: <span className="font-mono font-bold">{devCode}</span>
          </p>
        )}

        <div className="text-center text-[12.5px]" style={{ color: 'var(--text-tertiary)' }}>
          {cooldown > 0 ? (
            <span>ارسال مجدد کد تا {cooldown} ثانیه دیگر</span>
          ) : (
            <button type="button" onClick={resend} className="font-semibold" style={{ color: 'var(--color-brand-600)' }}>
              ارسال مجدد کد
            </button>
          )}
        </div>
      </div>
    </AuthScreen>
  )
}
