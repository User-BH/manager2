import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { KeyRound, Loader2, Lock, Phone } from 'lucide-react'
import { AuthScreen } from './components/AuthScreen'
import { OtpBoxes } from './components/OtpBoxes'
import { FormField } from './components/FormField'
import { PasswordStrength } from './components/PasswordStrength'
import { filterAsciiPassword, filterMobile } from '@/lib/inputFilters'
import { api, ApiError } from '@/lib/api'
import { toastSuccess } from '@/lib/alert'
import { useAuth } from '@/context/AuthContext'
import { useDocumentTitle } from '@/hooks'
import type { CurrentUser } from '@/types'

type Step = 'phone' | 'code' | 'reset'

const RESEND_SECONDS = 60

/**
 * بازیابی رمز عبور.
 *
 * ۱) فقط شماره موبایل؛ اثباتِ هویت خودِ کدِ پیامکی است.
 * ۲) شش خانه برای کد. به‌محضِ کاملِ‌شدن، بدون فشار دکمه بررسی می‌شود؛ اگر
 *    غلط بود پیام می‌دهد و «ارسال دوباره‌ی رمز یک‌بارمصرف» زیرش هست.
 * ۳) رمز تازه؛ پس از ثبت، کاربر خودکار وارد داشبورد می‌شود و لازم نیست
 *    دوباره فرم ورود را پر کند.
 */
export function ForgotPasswordPage() {
  const navigate = useNavigate()
  const { setUser } = useAuth()
  const [step, setStep] = useState<Step>('phone')

  useDocumentTitle('بازیابی رمز عبور')

  const [phone, setPhone] = useState('')
  const [code, setCode] = useState('')
  const [devCode, setDevCode] = useState<string | null>(null)
  const [cooldown, setCooldown] = useState(0)
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')

  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (cooldown <= 0) return
    const t = setTimeout(() => setCooldown((c) => c - 1), 1000)
    return () => clearTimeout(t)
  }, [cooldown])

  async function sendCode(e?: React.FormEvent) {
    e?.preventDefault()
    setBusy(true)
    setError(null)

    try {
      const data = await api<{ dev_code?: string | null }>('/password/forgot', {
        method: 'POST',
        body: { phone },
      })
      setDevCode(data.dev_code ?? null)
      setCooldown(RESEND_SECONDS)
      setCode('')
      setStep('code')
    } catch (err) {
      setError(err instanceof ApiError ? (err.fieldError('phone') ?? err.message) : 'ارتباط با سرور برقرار نشد.')
    } finally {
      setBusy(false)
    }
  }

  /** با کاملِ‌شدن شش رقم، خودکار صدا زده می‌شود. */
  async function verify(value: string) {
    if (busy) return
    setBusy(true)
    setError(null)

    try {
      await api('/password/forgot/verify', { method: 'POST', body: { code: value } })
      setStep('reset')
    } catch (err) {
      setError(
        err instanceof ApiError
          ? (err.fieldError('code') ?? err.message)
          : 'ارتباط با سرور برقرار نشد.',
      )
      setCode('')
    } finally {
      setBusy(false)
    }
  }

  async function reset(e: React.FormEvent) {
    e.preventDefault()

    if (password !== confirm) {
      setError('رمز عبور و تکرار آن یکسان نیستند.')
      return
    }

    setBusy(true)
    setError(null)

    try {
      /*
       * سرور پس از ثبت رمز تازه، خودش کاربر را وارد می‌کند و کاربر را
       * برمی‌گرداند؛ هویتش همین حالا با کد پیامکی اثبات شده، پس نه فرم ورود
       * لازم است نه یک پیامکِ دومرحله‌ایِ دیگر.
       */
      const { user } = await api<{ user: CurrentUser }>('/password/reset', {
        method: 'POST',
        body: { password, password_confirmation: confirm },
      })

      toastSuccess('رمز عبور تغییر کرد. خوش آمدید!')
      setUser(user)
      navigate('/dashboard', { replace: true })
    } catch (err) {
      setError(err instanceof ApiError ? (err.fieldError('password') ?? err.message) : 'ارتباط با سرور برقرار نشد.')
    } finally {
      setBusy(false)
    }
  }

  const subtitles: Record<Step, string> = {
    phone: 'شماره موبایل حساب خود را وارد کنید تا کد بازیابی برایتان فرستاده شود.',
    code: `کد شش‌رقمی به شماره ${phone} پیامک شد.`,
    reset: 'رمز عبور تازه‌ای انتخاب کنید.',
  }

  return (
    <AuthScreen title="بازیابی رمز عبور" subtitle={subtitles[step]}>
      {step === 'phone' && (
        <form onSubmit={sendCode} className="flex flex-col gap-4">
          <FormField
            label="شماره موبایل"
            icon={Phone}
            placeholder="۰۹xxxxxxxxx"
            inputMode="numeric"
            dir="ltr"
            autoComplete="username"
            value={phone}
            onChange={(e) => setPhone(filterMobile(e.target.value).value)}
            error={error ?? undefined}
          />

          <button
            type="submit"
            disabled={busy || phone.length < 11}
            className="mt-1 flex items-center justify-center gap-2 rounded-xl py-3.5 text-sm font-bold text-white transition-transform hover:scale-[1.02] disabled:opacity-60 disabled:hover:scale-100"
            style={{ backgroundColor: 'var(--color-brand-500)' }}
          >
            {busy ? <Loader2 size={17} className="animate-spin" /> : <KeyRound size={17} />}
            ارسال کد بازیابی
          </button>
        </form>
      )}

      {step === 'code' && (
        <div className="flex flex-col gap-5">
          <OtpBoxes
            value={code}
            onChange={setCode}
            onComplete={verify}
            disabled={busy}
            hasError={Boolean(error)}
            autoFocus
          />

          {busy && (
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
          {devCode && (
            <p className="text-center text-[11.5px]" style={{ color: 'var(--text-tertiary)' }}>
              کد تست: <span className="font-mono font-bold">{devCode}</span>
            </p>
          )}

          {/* ارسال دوباره، اگر پیامک به دست کاربر نرسید */}
          <div className="text-center text-[12.5px]" style={{ color: 'var(--text-tertiary)' }}>
            {cooldown > 0 ? (
              <span>ارسال دوباره‌ی رمز یک‌بارمصرف تا {cooldown} ثانیه دیگر</span>
            ) : (
              <button
                type="button"
                onClick={() => void sendCode()}
                className="font-semibold underline"
                style={{ color: 'var(--color-brand-600)' }}
              >
                ارسال دوباره‌ی رمز یک‌بارمصرف
              </button>
            )}
          </div>
        </div>
      )}

      {step === 'reset' && (
        <form onSubmit={reset} className="flex flex-col gap-4">
          <div>
            <FormField
              label="رمز عبور تازه"
              icon={Lock}
              type="password"
              placeholder="حداقل ۸ نویسه، شامل حرف و عدد"
              dir="ltr"
              autoComplete="new-password"
              value={password}
              onChange={(e) => setPassword(filterAsciiPassword(e.target.value).value)}
            />
            <PasswordStrength value={password} />
          </div>

          <FormField
            label="تکرار رمز عبور"
            icon={Lock}
            type="password"
            placeholder="تکرار رمز تازه"
            dir="ltr"
            autoComplete="new-password"
            value={confirm}
            onChange={(e) => setConfirm(filterAsciiPassword(e.target.value).value)}
          />

          {error && (
            <p className="text-[12.5px]" style={{ color: 'var(--color-danger)' }}>
              {error}
            </p>
          )}

          <button
            type="submit"
            disabled={busy || password.length < 8 || !confirm}
            className="mt-1 flex items-center justify-center gap-2 rounded-xl py-3.5 text-sm font-bold text-white transition-transform hover:scale-[1.02] disabled:opacity-60 disabled:hover:scale-100"
            style={{ backgroundColor: 'var(--color-brand-500)' }}
          >
            {busy ? <Loader2 size={17} className="animate-spin" /> : <KeyRound size={17} />}
            ثبت رمز تازه و ورود
          </button>
        </form>
      )}
    </AuthScreen>
  )
}
