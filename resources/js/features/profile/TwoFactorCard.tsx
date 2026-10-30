import { useState } from 'react'
import { toAsciiDigits } from '@/shared/lib/inputFilters'
import { useQuery } from '@tanstack/react-query'
import { Copy, KeyRound, Loader2, ShieldAlert, ShieldCheck } from 'lucide-react'
import { Card } from '@/shared/ui/Card'
import { QrCode } from '@/shared/ui/QrCode'
import { api, ApiError } from '@/shared/lib/api'
import { toastSuccess } from '@/shared/lib/alert'
import { copyText } from '@/shared/lib/clipboard'
import { queryKeys } from '@/shared/lib/queryKeys'
import { formatNumber } from '@/shared/lib/format'

interface Status {
  enabled: boolean
  setupRequired: boolean
  confirmedAt: string | null
  recoveryCodesRemaining: number
}

/** گامِ جاری: بی کار، اسکنِ QR ِ کلیدِ تازه، نمایشِ یک‌باره‌ی کدهای بازیابی، یا پرسیدنِ کدِ فعلی پیش از تعویض/کدهای تازه */
type Flow =
  | { step: 'idle' }
  | { step: 'current'; intent: 'rotate' | 'regenerate' }
  | { step: 'scan'; secret: string; uri: string }
  | { step: 'codes'; codes: string[] }

const STATUS_KEY = [...queryKeys.profile.all(), 'two-factor'] as const

/**
 * ورودِ دومرحله‌ای با Authenticator ِ سوپر ادمین (دستورِ یکپارچه‌ی ۲۰۲۶-۱۰-۲۵، بخشِ ۵) — فقط در «اطلاعاتِ کاربری» ِ
 * سوپر ادمین و تنبل‌بار.
 *
 * کلید فقط در گامِ اسکن نشان داده می‌شود و کدهای بازیابی فقط همان لحظه‌ی ساخت؛ هیچ‌کدام در کش یا حافظه‌ی مرورگر
 * نمی‌ماند (state ِ همین کامپوننت). تأییدِ رمز را لایه‌ی `api()` خودش می‌پرسد (`auth.reauth_required`). غیرفعال‌سازی
 * دکمه ندارد: عاملِ دوم الزامی است و بازنشانی فقط با فرمانِ سرور.
 */
export default function TwoFactorCard() {
  const [flow, setFlow] = useState<Flow>({ step: 'idle' })
  const [code, setCode] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const { data, refetch } = useQuery({
    queryKey: STATUS_KEY,
    queryFn: ({ signal }) => api<Status>('/profile/two-factor', { signal }),
  })

  async function run<T>(path: string, body: Record<string, string>, then: (result: T) => void) {
    setBusy(true)
    setError(null)
    try {
      then(await api<T>(path, { method: 'POST', body }))
      setCode('')
    } catch (err) {
      setError(
        err instanceof ApiError
          ? (err.fieldError('code') ?? err.message)
          : 'ارتباط با سرور برقرار نشد.',
      )
    } finally {
      setBusy(false)
    }
  }

  const startSetup = (body: Record<string, string> = {}) =>
    run<{ secret: string; uri: string }>('/profile/two-factor/setup', body, (r) =>
      setFlow({ step: 'scan', secret: r.secret, uri: r.uri }),
    )

  const submitCurrent = (intent: 'rotate' | 'regenerate') =>
    intent === 'rotate'
      ? startSetup({ code })
      : run<{ recoveryCodes: string[] }>('/profile/two-factor/recovery-codes', { code }, (r) => {
          setFlow({ step: 'codes', codes: r.recoveryCodes })
          void refetch()
        })

  const confirm = () =>
    run<{ recoveryCodes: string[] }>('/profile/two-factor/confirm', { code }, (r) => {
      setFlow({ step: 'codes', codes: r.recoveryCodes })
      void refetch()
    })

  if (!data) return null

  const codeInput = (onEnter: () => void) => (
    <input
      value={code}
      // رقمِ فارسیِ چسبانده هم پذیرفته می‌شود (۲۰۲۶-۱۰-۳۰، بندِ ۵)
      onChange={(e) => setCode(toAsciiDigits(e.target.value).replace(/\D/g, '').slice(0, 6))}
      onKeyDown={(e) => {
        if (e.key === 'Enter' && code.length === 6) onEnter()
      }}
      inputMode="numeric"
      autoComplete="one-time-code"
      dir="ltr"
      placeholder="۶ رقمِ برنامه"
      aria-label="کد شش‌رقمیِ برنامه‌ی Authenticator"
      className="field w-40 rounded-xl px-3 py-2 text-center font-mono text-sm tracking-[0.3em]"
    />
  )

  const action = (label: string, onClick: () => void, disabled = false) => (
    <button
      type="button"
      onClick={onClick}
      disabled={busy || disabled}
      className="flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-bold text-white disabled:opacity-60"
      style={{ backgroundColor: 'var(--color-brand-500)' }}
    >
      {busy && <Loader2 size={14} className="animate-spin" />}
      {label}
    </button>
  )

  return (
    <Card
      title="ورود دومرحله‌ای (Authenticator)"
      subtitle="رمز به‌تنهایی کافی نیست: هر ورود کدِ شش‌رقمیِ برنامه‌ی Authenticator یا یک کدِ بازیابی می‌خواهد."
      delay={0.15}
    >
      <div className="flex flex-col gap-4 text-xs" style={{ color: 'var(--text-secondary)' }}>
        <p
          className="flex items-center gap-2 font-semibold"
          style={{ color: data.enabled ? 'var(--state-success)' : 'var(--color-danger)' }}
        >
          {data.enabled ? <ShieldCheck size={16} /> : <ShieldAlert size={16} />}
          {data.enabled
            ? `فعال از ${data.confirmedAt ?? '—'} · ${formatNumber(data.recoveryCodesRemaining)} کدِ بازیابیِ باقی‌مانده`
            : data.setupRequired
              ? 'غیرفعال — تا فعال‌سازی، بخش‌های مدیریتی بسته‌اند.'
              : 'هنوز فعال نشده است.'}
        </p>

        {flow.step === 'idle' && (
          <div className="flex flex-wrap gap-2">
            {data.enabled ? (
              <>
                {action('تعویضِ برنامه یا گوشی', () =>
                  setFlow({ step: 'current', intent: 'rotate' }),
                )}
                {action('کدهای بازیابیِ تازه', () =>
                  setFlow({ step: 'current', intent: 'regenerate' }),
                )}
              </>
            ) : (
              action('راه‌اندازی', () => void startSetup())
            )}
          </div>
        )}

        {flow.step === 'current' && (
          <div className="flex flex-wrap items-center gap-2">
            <span>کدِ فعلیِ برنامه را وارد کنید:</span>
            {codeInput(() => void submitCurrent(flow.intent))}
            {action('ادامه', () => void submitCurrent(flow.intent), code.length !== 6)}
          </div>
        )}

        {flow.step === 'scan' && (
          <div className="flex flex-col items-start gap-3">
            <p>
              این QR را با برنامه‌ی Authenticator اسکن کنید، یا کلید را دستی وارد کنید؛ سپس کدِ
              شش‌رقمیِ آن را بزنید.
            </p>
            <div className="rounded-xl bg-white p-2">
              <QrCode value={flow.uri} label="QR ِ کلیدِ Authenticator" size={176} />
            </div>
            <code
              dir="ltr"
              className="select-all break-all rounded-lg px-2 py-1 font-mono text-xs"
              style={{ backgroundColor: 'var(--surface-sunken)' }}
            >
              {flow.secret.replace(/(.{4})/g, '$1 ').trim()}
            </code>
            <div className="flex flex-wrap items-center gap-2">
              {codeInput(() => void confirm())}
              {action('تأیید و فعال‌سازی', () => void confirm(), code.length !== 6)}
            </div>
          </div>
        )}

        {flow.step === 'codes' && (
          <div className="flex flex-col items-start gap-3">
            <p
              className="flex items-center gap-1.5 font-semibold"
              style={{ color: 'var(--text-primary)' }}
            >
              <KeyRound size={15} />
              کدهای بازیابی — فقط همین یک بار نشان داده می‌شوند. هر کد یک بار کار می‌کند.
            </p>
            <ul
              dir="ltr"
              className="grid grid-cols-2 gap-x-6 gap-y-1 font-mono text-xs"
              style={{ color: 'var(--text-primary)' }}
            >
              {flow.codes.map((c) => (
                <li key={c}>{c}</li>
              ))}
            </ul>
            <div className="flex flex-wrap gap-2">
              <button
                type="button"
                onClick={() =>
                  void copyText(flow.codes.join('\n')).then(
                    (ok) => ok && toastSuccess('کدها کپی شد.'),
                  )
                }
                className="flex items-center gap-1.5 rounded-xl border px-3 py-2 text-xs font-semibold"
                style={{ borderColor: 'var(--border-default)' }}
              >
                <Copy size={14} />
                کپی
              </button>
              {action('جایی امن نگه داشتم', () => setFlow({ step: 'idle' }))}
            </div>
          </div>
        )}

        {error && (
          <p role="alert" style={{ color: 'var(--color-danger)' }}>
            {error}
          </p>
        )}
      </div>
    </Card>
  )
}
