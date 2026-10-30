import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2, ShieldCheck, Smartphone } from 'lucide-react'
import { z } from 'zod'
import { api, ApiError } from '@/shared/lib/api'
import { useAuth } from '@/shared/stores/authStore'
import { useReauthStore } from '@/shared/stores/reauthStore'
import { TextField } from './Field'
import { Modal } from './Modal'

/**
 * پنجره‌ی «دوباره تأیید کنید» (مرحلهٔ ۵ · فاز ۱۰).
 *
 * خودش هیچ‌جا باز نمی‌شود: سرور پیش از عملیاتِ حساس `auth.reauth_required` می‌دهد، `api()` این پنجره را از `reauthStore`
 * می‌خواهد، و پس از تأیید همان درخواست را دوباره می‌فرستد.
 *
 * از ۲۰۲۶-۱۰-۳۰ دو شکل دارد: دو مدیرِ سامانه رمزشان را می‌زنند؛ بقیه — که رمز ندارند — کدِ پیامکیِ شماره‌ی خودشان را
 * (`profile/confirm-code`). شکل را سرور هم از نقش می‌سنجد، نه از این پنجره.
 */
export function ReauthDialog() {
  const { open, settle } = useReauthStore()
  const { user } = useAuth()

  if (!open) return null

  return user?.usesPassword ? <PasswordForm settle={settle} /> : <CodeForm settle={settle} />
}

const passwordSchema = z.object({ password: z.string().min(1, 'رمز عبور را وارد کنید.') })
type PasswordValues = z.infer<typeof passwordSchema>

function PasswordForm({ settle }: { settle: (confirmed: boolean) => void }) {
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<PasswordValues>({
    resolver: zodResolver(passwordSchema),
    defaultValues: { password: '' },
  })

  async function submit({ password }: PasswordValues) {
    try {
      await api('/profile/confirm-password', { method: 'POST', body: { password } })
      settle(true)
    } catch (caught) {
      setError('password', {
        message:
          caught instanceof ApiError
            ? (caught.fieldError('password') ?? caught.message)
            : 'تأیید ممکن نشد. دوباره تلاش کنید.',
      })
    }
  }

  return (
    <Shell settle={settle} onSubmit={handleSubmit(submit)} busy={isSubmitting}>
      <Explain>
        این کار حساس است. برای اطمینان از اینکه خودتان هستید، رمز عبورتان را دوباره وارد کنید.
      </Explain>
      <TextField
        label="رمز عبور"
        type="password"
        autoComplete="current-password"
        {...register('password')}
        error={errors.password?.message}
      />
    </Shell>
  )
}

function CodeForm({ settle }: { settle: (confirmed: boolean) => void }) {
  const [sent, setSent] = useState(false)
  const [sending, setSending] = useState(false)
  const [devCode, setDevCode] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function send() {
    setSending(true)
    setError(null)
    try {
      const data = await api<{ dev_code?: string | null }>('/profile/confirm-code', {
        method: 'POST',
      })
      setDevCode(data.dev_code ?? null)
      setSent(true)
    } catch (caught) {
      setError(
        caught instanceof ApiError
          ? (caught.fieldError('code') ?? caught.message)
          : 'ارسال کد ممکن نشد.',
      )
    } finally {
      setSending(false)
    }
  }

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    if (!sent) return void send()
    setBusy(true)
    setError(null)
    try {
      await api('/profile/confirm-password', { method: 'POST', body: { code } })
      settle(true)
    } catch (caught) {
      setError(
        caught instanceof ApiError
          ? (caught.fieldError('code') ?? caught.message)
          : 'تأیید ممکن نشد. دوباره تلاش کنید.',
      )
    } finally {
      setBusy(false)
    }
  }

  return (
    <Shell
      settle={settle}
      onSubmit={submit}
      busy={busy || sending}
      submitLabel={sent ? 'تأیید' : 'ارسال کد'}
    >
      <Explain>
        این کار حساس است. برای اطمینان از اینکه خودتان هستید، کدی به شماره‌ی موبایلتان فرستاده
        می‌شود.
      </Explain>
      {sent ? (
        <>
          <TextField
            label="کدِ پیامک‌شده"
            inputMode="numeric"
            autoComplete="one-time-code"
            dir="ltr"
            value={code}
            // رقمِ فارسی و کدِ چسبانده هم پذیرفته می‌شود
            onChange={(e) =>
              setCode(
                e.target.value
                  .replace(/[۰-۹]/g, (d) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)))
                  .replace(/\D/g, '')
                  .slice(0, 6),
              )
            }
            error={error ?? undefined}
          />
          {devCode && (
            <p className="text-xs" style={{ color: 'var(--text-tertiary)' }}>
              کدِ محیطِ آزمایشی: {devCode}
            </p>
          )}
        </>
      ) : (
        error && (
          <p className="text-small" role="alert" style={{ color: 'var(--color-danger)' }}>
            {error}
          </p>
        )
      )}
    </Shell>
  )
}

function Explain({ children }: { children: React.ReactNode }) {
  return (
    <p
      className="flex items-start gap-2 text-small leading-6"
      style={{ color: 'var(--text-secondary)' }}
    >
      <ShieldCheck
        size={18}
        className="mt-0.5 shrink-0"
        style={{ color: 'var(--color-brand-500)' }}
      />
      {children}
    </p>
  )
}

function Shell({
  settle,
  onSubmit,
  busy,
  submitLabel = 'تأیید',
  children,
}: {
  settle: (confirmed: boolean) => void
  onSubmit: (event: React.FormEvent<HTMLFormElement>) => void
  busy: boolean
  submitLabel?: string
  children: React.ReactNode
}) {
  return (
    <Modal open title="تأییدِ دوباره" onClose={() => settle(false)}>
      <form onSubmit={onSubmit} className="flex flex-col gap-4 p-5">
        {children}
        <div className="flex justify-end gap-2">
          <button
            type="button"
            onClick={() => settle(false)}
            className="rounded-xl border px-4 py-2 text-small"
            style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
          >
            انصراف
          </button>
          <button
            type="submit"
            disabled={busy}
            className="flex items-center gap-2 rounded-xl px-4 py-2 text-small font-semibold text-white disabled:opacity-50"
            style={{ backgroundColor: 'var(--color-brand-500)' }}
          >
            {busy ? (
              <Loader2 size={16} className="animate-spin" />
            ) : (
              submitLabel === 'ارسال کد' && <Smartphone size={16} />
            )}
            {submitLabel}
          </button>
        </div>
      </form>
    </Modal>
  )
}
