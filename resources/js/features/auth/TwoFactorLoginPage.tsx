import { useState } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { KeyRound, Loader2, ShieldCheck } from 'lucide-react'
import { AuthScreen } from './components/AuthScreen'
import { OtpBoxes } from './components/OtpBoxes'
import { api, ApiError } from '@/shared/lib/api'
import { useDocumentTitle } from '@/shared/hooks'
import { afterSignIn } from './joinCode'

/**
 * گامِ دومِ ورودِ سوپر ادمین (دستورِ یکپارچه‌ی ۲۰۲۶-۱۰-۲۵، بخشِ ۵): کدِ شش‌رقمیِ برنامه‌ی Authenticator، یا یکی از
 * کدهای بازیابیِ یک‌بارمصرف وقتی گوشی در دسترس نیست. هیچ پیامکی در کار نیست.
 *
 * فقط پس از گامِ رمز می‌آید (`state.fromPassword`)؛ مهلت، شمارِ تلاش و قفلِ حساب در سرور است و این صفحه فقط پیامش را
 * نشان می‌دهد. صفحه‌ی نادر و تنبل‌بار — در بارگذاریِ اولِ ورودِ کاربرانِ عادی نیست.
 */
export default function TwoFactorLoginPage() {
  useDocumentTitle('ورود دومرحله‌ای')
  const location = useLocation()
  const fromPassword = (location.state as { fromPassword?: boolean } | null)?.fromPassword === true

  const [useRecovery, setUseRecovery] = useState(false)
  const [code, setCode] = useState('')
  const [recovery, setRecovery] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  if (!fromPassword) return <Navigate to="/auth" replace />

  async function submit(body: { code: string } | { recovery_code: string }) {
    if (submitting) return
    setSubmitting(true)
    setError(null)

    try {
      await api('/login/two-factor', { method: 'POST', body })
      window.location.replace(afterSignIn())
    } catch (err) {
      setError(
        err instanceof ApiError
          ? (err.fieldError('code') ?? err.fieldError('recovery_code') ?? err.message)
          : 'ارتباط با سرور برقرار نشد.',
      )
      setCode('')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <AuthScreen
      title="ورود دومرحله‌ای"
      subtitle={
        useRecovery
          ? 'یکی از کدهای بازیابیِ یک‌بارمصرف را وارد کنید.'
          : 'کد شش‌رقمیِ برنامه‌ی Authenticator را وارد کنید.'
      }
    >
      <div className="flex flex-col gap-5">
        <span
          className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl"
          style={{ backgroundColor: 'color-mix(in srgb, var(--color-brand-500) 14%, transparent)' }}
        >
          {useRecovery ? (
            <KeyRound size={24} style={{ color: 'var(--color-brand-600)' }} />
          ) : (
            <ShieldCheck size={24} style={{ color: 'var(--color-brand-600)' }} />
          )}
        </span>

        {useRecovery ? (
          // یک فیلد و یک دکمه، بی تگِ فرم (فرم‌های چندفیلدیِ پروژه با react-hook-form‌اند)؛ Enter همان دکمه است
          <div className="flex flex-col gap-3">
            <label className="flex flex-col gap-1.5 text-xs font-semibold">
              کد بازیابی
              <input
                value={recovery}
                onChange={(e) => setRecovery(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' && recovery.trim())
                    void submit({ recovery_code: recovery.trim() })
                }}
                dir="ltr"
                autoComplete="one-time-code"
                placeholder="xxxxx-xxxxx"
                className="field rounded-xl px-3 py-2.5 font-mono text-sm"
              />
            </label>
            <button
              type="button"
              onClick={() => void submit({ recovery_code: recovery.trim() })}
              disabled={submitting || !recovery.trim()}
              className="rounded-xl py-3 text-sm font-bold text-white disabled:opacity-60"
              style={{ backgroundColor: 'var(--color-brand-500)' }}
            >
              ورود با کد بازیابی
            </button>
          </div>
        ) : (
          <OtpBoxes
            value={code}
            onChange={setCode}
            onComplete={(value) => void submit({ code: value })}
            disabled={submitting}
            hasError={Boolean(error)}
            // تنها کارِ این گام واردکردنِ همین کد است (همان دلیلِ صفحه‌ی کدِ پیامکی)
            // eslint-disable-next-line jsx-a11y/no-autofocus
            autoFocus
          />
        )}

        {submitting && (
          <div
            className="flex items-center justify-center gap-2 text-sm"
            style={{ color: 'var(--text-secondary)' }}
          >
            <Loader2 size={15} className="animate-spin" />
            در حال تایید…
          </div>
        )}

        {error && (
          <p role="alert" className="text-center text-xs" style={{ color: 'var(--color-danger)' }}>
            {error}
          </p>
        )}

        <button
          type="button"
          onClick={() => {
            setUseRecovery((v) => !v)
            setError(null)
          }}
          className="text-center text-xs font-semibold"
          style={{ color: 'var(--color-brand-600)' }}
        >
          {useRecovery ? 'ورود با کد برنامه' : 'گوشی در دسترس نیست؟ ورود با کد بازیابی'}
        </button>
      </div>
    </AuthScreen>
  )
}
