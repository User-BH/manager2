import { useEffect, useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { KeyRound, Loader2, Lock, Phone } from 'lucide-react'
import { AuthScreen } from './components/AuthScreen'
import { OtpBoxes } from './components/OtpBoxes'
import { RestrictedField } from './components/RestrictedField'
import { PasswordStrength } from './components/PasswordStrength'
import {
  forgotPhoneSchema,
  resetPasswordSchema,
  type ForgotPhoneValues,
  type ResetPasswordValues,
} from './schemas/forgotSchema'
import { filterAsciiPassword, filterMobile } from '@/shared/lib/inputFilters'
import { api, ApiError } from '@/shared/lib/api'
import { toastSuccess } from '@/shared/lib/alert'
import { useAutoFocus, useDocumentTitle } from '@/shared/hooks'
import type { CurrentUser } from '@/shared/types'

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
  const [step, setStep] = useState<Step>('phone')

  useDocumentTitle('بازیابی رمز عبور')

  const [code, setCode] = useState('')
  const [devCode, setDevCode] = useState<string | null>(null)
  const [cooldown, setCooldown] = useState(0)

  /*
   * `error` فقط برای خطای **گامِ کد** مانده.
   *
   * دو گامِ دیگر خطاهایشان را از خودِ فرم می‌گیرند؛ گامِ کد `<form>` ندارد
   * (شش خانه‌ی OTP که با کاملِ‌شدن خودکار بررسی می‌شوند) پس همچنان حالتِ
   * خودش را دارد.
   */
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  /*
   * ⚠️ دو فرمِ جدا و نه یکی (R40).
   *
   * گامِ شماره و گامِ رمزِ تازه هیچ فیلدِ مشترکی ندارند و هرگز هم‌زمان روی
   * صفحه نیستند. یک فرمِ واحد یعنی اسکیمایی که نیمی از فیلدهایش همیشه
   * خالی‌اند و باید شرطی اعتبارسنجی شوند — پیچیدگی‌ای که هیچ چیزی
   * نمی‌خرد.
   */
  const phoneForm = useForm<ForgotPhoneValues>({
    resolver: zodResolver(forgotPhoneSchema),
    defaultValues: { phone: '' },
  })

  const resetForm = useForm<ResetPasswordValues>({
    resolver: zodResolver(resetPasswordSchema),
    defaultValues: { password: '', confirmPassword: '' },
  })

  const phone = useWatch({ control: phoneForm.control, name: 'phone' })
  const password = useWatch({ control: resetForm.control, name: 'password' })

  const phoneFormRef = useAutoFocus<HTMLFormElement>(step === 'phone')
  const resetFormRef = useAutoFocus<HTMLFormElement>(step === 'reset')

  useEffect(() => {
    if (cooldown <= 0) return
    const t = setTimeout(() => setCooldown((c) => c - 1), 1000)
    return () => clearTimeout(t)
  }, [cooldown])

  async function sendCode(values: ForgotPhoneValues) {
    setBusy(true)
    setError(null)

    try {
      const data = await api<{ dev_code?: string | null }>('/password/forgot', {
        method: 'POST',
        body: { phone: values.phone },
      })
      setDevCode(data.dev_code ?? null)
      setCooldown(RESEND_SECONDS)
      setCode('')
      setStep('code')
    } catch (err) {
      // خطای سرور زیرِ همان فیلد می‌نشیند، نه در یک پیامِ شناور
      phoneForm.setError('phone', {
        message:
          err instanceof ApiError
            ? (err.fieldError('phone') ?? err.message)
            : 'ارتباط با سرور برقرار نشد.',
      })
    } finally {
      setBusy(false)
    }
  }

  /** ارسالِ دوباره از دکمه‌ی «ارسال دوباره» — همان مقدارِ فرم را می‌فرستد. */
  function resend() {
    void sendCode({ phone })
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

  async function reset(values: ResetPasswordValues) {
    setBusy(true)
    setError(null)

    try {
      /*
       * سرور پس از ثبت رمز تازه، خودش کاربر را وارد می‌کند و کاربر را
       * برمی‌گرداند؛ هویتش همین حالا با کد پیامکی اثبات شده، پس نه فرم ورود
       * لازم است نه یک پیامکِ دومرحله‌ایِ دیگر.
       */
      await api<{ user: CurrentUser }>('/password/reset', {
        method: 'POST',
        body: { password: values.password, password_confirmation: values.confirmPassword },
      })

      toastSuccess('رمز عبور تغییر کرد. خوش آمدید!')
      // ورود خودکار انجام شده؛ به داشبورد (سندِ جدا) می‌رویم
      window.location.assign('/dashboard')
    } catch (err) {
      resetForm.setError('password', {
        message:
          err instanceof ApiError
            ? (err.fieldError('password') ?? err.message)
            : 'ارتباط با سرور برقرار نشد.',
      })
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
        <form
          ref={phoneFormRef}
          onSubmit={phoneForm.handleSubmit(sendCode)}
          className="flex flex-col gap-4"
          noValidate
        >
          <RestrictedField
            control={phoneForm.control}
            name="phone"
            label="شماره موبایل"
            icon={Phone}
            placeholder="۰۹xxxxxxxxx"
            inputMode="numeric"
            dir="ltr"
            autoComplete="username"
            filter={filterMobile}
            hint="فقط رقم انگلیسی وارد کنید"
            error={phoneForm.formState.errors.phone?.message}
          />

          {/*
            ⚠️ دکمه دیگر با طولِ ورودی غیرفعال نمی‌شود.
            پیش از این `disabled={phone.length < 11}` بود؛ کاربر دکمه‌ی
            خاموش می‌دید بی‌آنکه بداند چرا. حالا کلیک می‌کند و پیامِ روشن
            زیرِ فیلد می‌آید.
          */}
          <button
            type="submit"
            disabled={busy}
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
            // autoFocus آگاهانه است: کاربر همین لحظه درخواستِ کد کرده و تنها کارِ
            // این مرحله واردکردنِ همان کد است؛ در موبایل یک لمس کم می‌کند و فوکوس
            // را از جای دیگری نمی‌دزدد.
            // eslint-disable-next-line jsx-a11y/no-autofocus
            autoFocus
          />

          {busy && (
            <div
              className="flex items-center justify-center gap-2 text-[13px]"
              style={{ color: 'var(--text-secondary)' }}
            >
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
                onClick={resend}
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
        <form
          ref={resetFormRef}
          onSubmit={resetForm.handleSubmit(reset)}
          className="flex flex-col gap-4"
          noValidate
        >
          <div>
            <RestrictedField
              control={resetForm.control}
              name="password"
              label="رمز عبور تازه"
              icon={Lock}
              type="password"
              placeholder="حداقل ۸ نویسه، شامل حرف و عدد"
              dir="ltr"
              autoComplete="new-password"
              filter={filterAsciiPassword}
              hint="رمز فقط با حروف انگلیسی و رقم"
              error={resetForm.formState.errors.password?.message}
            />
            <PasswordStrength value={password} />
          </div>

          <RestrictedField
            control={resetForm.control}
            name="confirmPassword"
            label="تکرار رمز عبور"
            icon={Lock}
            type="password"
            placeholder="تکرار رمز تازه"
            dir="ltr"
            autoComplete="new-password"
            filter={filterAsciiPassword}
            hint="رمز فقط با حروف انگلیسی و رقم"
            error={resetForm.formState.errors.confirmPassword?.message}
          />

          <button
            type="submit"
            disabled={busy}
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
