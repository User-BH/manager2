import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Building2, KeyRound, Loader2, Lock, Phone } from 'lucide-react'
import { AuthScreen } from './components/AuthScreen'
import { OtpBoxes } from './components/OtpBoxes'
import { FormField } from './components/FormField'
import { JalaliDatePicker } from '@/components/ui/JalaliDatePicker'
import {
  filterAsciiPassword,
  filterMobile,
  filterPersianAlphanumeric,
} from '@/lib/inputFilters'
import { api, ApiError } from '@/lib/api'
import { toastSuccess } from '@/lib/alert'
import { useDocumentTitle } from '@/hooks'

type Step = 'identify' | 'code' | 'reset'

const RESEND_SECONDS = 60

/**
 * بازیابی رمز عبور در سه گام.
 *
 * ۱) هویت: شماره موبایل + نام مجتمع + تاریخ تولد. اگر با هم بخوانند کد
 *    پیامک می‌شود. (خطای عمومی تا نشود با آزمون‌وخطا اطلاعات استخراج کرد.)
 * ۲) کد: تایید کد شش‌رقمی.
 * ۳) رمز تازه: ورود و تکرار رمز، سپس بازگشت به صفحه‌ی ورود.
 */
export function ForgotPasswordPage() {
  const navigate = useNavigate()
  const [step, setStep] = useState<Step>('identify')

  useDocumentTitle('بازیابی رمز عبور')

  // گام ۱
  const [phone, setPhone] = useState('')
  const [complexName, setComplexName] = useState('')
  const [birthDate, setBirthDate] = useState('')

  // گام ۲
  const [code, setCode] = useState('')
  const [devCode, setDevCode] = useState<string | null>(null)
  const [cooldown, setCooldown] = useState(0)

  // گام ۳
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')

  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (cooldown <= 0) return
    const t = setTimeout(() => setCooldown((c) => c - 1), 1000)
    return () => clearTimeout(t)
  }, [cooldown])

  async function identify(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError(null)

    try {
      const data = await api<{ dev_code?: string | null }>('/password/forgot', {
        method: 'POST',
        body: { phone, complex_name: complexName, birth_date: birthDate },
      })
      setDevCode(data.dev_code ?? null)
      setCooldown(RESEND_SECONDS)
      setStep('code')
    } catch (err) {
      setError(err instanceof ApiError ? (err.fieldError('phone') ?? err.message) : 'ارتباط با سرور برقرار نشد.')
    } finally {
      setBusy(false)
    }
  }

  async function verify(value: string) {
    if (busy) return
    setBusy(true)
    setError(null)

    try {
      await api('/password/forgot/verify', { method: 'POST', body: { code: value } })
      setStep('reset')
    } catch (err) {
      setError(err instanceof ApiError ? (err.fieldError('code') ?? err.message) : 'ارتباط با سرور برقرار نشد.')
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
      await api('/password/reset', {
        method: 'POST',
        body: { password, password_confirmation: confirm },
      })
      toastSuccess('رمز عبور تغییر کرد. اکنون وارد شوید.')
      navigate('/auth', { replace: true })
    } catch (err) {
      setError(err instanceof ApiError ? (err.fieldError('password') ?? err.message) : 'ارتباط با سرور برقرار نشد.')
    } finally {
      setBusy(false)
    }
  }

  async function resend() {
    try {
      const data = await api<{ dev_code?: string | null }>('/password/forgot', {
        method: 'POST',
        body: { phone, complex_name: complexName, birth_date: birthDate },
      })
      setDevCode(data.dev_code ?? null)
      setCooldown(RESEND_SECONDS)
    } catch {
      setError('ارسال مجدد کد ممکن نشد.')
    }
  }

  const subtitles: Record<Step, string> = {
    identify: 'برای بازیابی، هویت خود را تایید کنید.',
    code: `کد شش‌رقمی به شماره ${phone} پیامک شد.`,
    reset: 'رمز عبور تازه‌ای انتخاب کنید.',
  }

  return (
    <AuthScreen title="بازیابی رمز عبور" subtitle={subtitles[step]}>
      {step === 'identify' && (
        <form onSubmit={identify} className="flex flex-col gap-4">
          <FormField
            label="شماره موبایل"
            icon={Phone}
            placeholder="۰۹xxxxxxxxx"
            inputMode="numeric"
            dir="ltr"
            value={phone}
            onChange={(e) => setPhone(filterMobile(e.target.value).value)}
          />
          <FormField
            label="نام مجتمع"
            icon={Building2}
            placeholder="مثلاً مجتمع نگین"
            value={complexName}
            onChange={(e) => setComplexName(filterPersianAlphanumeric(e.target.value).value)}
          />
          <JalaliDatePicker label="تاریخ تولد" value={birthDate} onChange={setBirthDate} maxToday />

          {error && (
            <p className="text-[12.5px]" style={{ color: 'var(--color-danger)' }}>
              {error}
            </p>
          )}

          <button
            type="submit"
            disabled={busy || !phone || !complexName || !birthDate}
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
          <OtpBoxes value={code} onChange={setCode} onComplete={verify} disabled={busy} hasError={Boolean(error)} autoFocus />

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
      )}

      {step === 'reset' && (
        <form onSubmit={reset} className="flex flex-col gap-4">
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
            ثبت رمز تازه
          </button>
        </form>
      )}
    </AuthScreen>
  )
}
