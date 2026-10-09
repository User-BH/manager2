import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Building2, Loader2 } from 'lucide-react'
import { AuthScreen } from './components/AuthScreen'
import { inviteToken } from './inviteToken'
import { api } from '@/shared/lib/api'
import { useDocumentTitle } from '@/shared/hooks'

interface InvitePreview {
  complexName: string | null
  roleLabel: string
  unitLabel: string | null
  phoneHint: string
  expiresAt: string | null
}

type State =
  { kind: 'loading' } | { kind: 'invalid' } | { kind: 'ready'; invitation: InvitePreview }

/**
 * پیوندِ دعوت (مرحلهٔ ۹ · فاز ۶) — فقط **پیش‌نمایش**.
 *
 * توکن پس از `#` می‌آید: نه به سرور فرستاده می‌شود و نه در آمارِ بازدید
 * (`trackPageView` فقط مسیر را می‌فرستد). همین‌جا با POST به سرور می‌رود و
 * بلافاصله از نوارِ نشانی پاک می‌شود.
 *
 * ⚠️ این صفحه هیچ دسترسی‌ای نمی‌دهد: پذیرش فقط در داشبوردِ اولیه، از حسابِ
 * همان شماره و با کدِ پیامکیِ تازه است.
 */
export function InvitePage() {
  useDocumentTitle('دعوت به مجتمع')

  // توکن را نقطه‌ی ورود پیش از پایش و تحلیل از نشانی برداشته است (`inviteToken`)
  const [token] = useState(inviteToken)
  const [state, setState] = useState<State>(() =>
    token ? { kind: 'loading' } : { kind: 'invalid' },
  )

  useEffect(() => {
    if (!token) return

    const controller = new AbortController()

    api<{ invitation: InvitePreview }>('/invitations/preview', {
      method: 'POST',
      body: { token },
      signal: controller.signal,
    })
      .then(({ invitation }) => setState({ kind: 'ready', invitation }))
      .catch(() => {
        if (!controller.signal.aborted) setState({ kind: 'invalid' })
      })

    return () => controller.abort()
  }, [token])

  if (state.kind === 'loading') {
    return (
      <AuthScreen title="دعوت به مجتمع">
        <p
          className="flex items-center justify-center gap-2 text-sm"
          style={{ color: 'var(--text-secondary)' }}
        >
          <Loader2 size={16} className="animate-spin" />
          در حال بررسیِ پیوند…
        </p>
      </AuthScreen>
    )
  }

  if (state.kind === 'invalid') {
    return (
      <AuthScreen
        title="این پیوند معتبر نیست"
        subtitle="پیوندِ دعوت فقط یک بار و تا هفت روز کار می‌کند. اگر منقضی یا باطل شده، از مدیرِ مجتمع بخواهید دوباره بفرستد."
      >
        <Link
          to="/auth"
          replace
          className="block rounded-xl py-3 text-center text-sm font-bold text-white"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          ورود به ساکنا
        </Link>
      </AuthScreen>
    )
  }

  const { invitation } = state

  return (
    <AuthScreen
      title="شما به یک مجتمع دعوت شده‌اید"
      subtitle={`با شماره‌ی ${invitation.phoneHint} وارد یا ثبت‌نام کنید؛ دعوت در داشبوردتان منتظر است و با کدِ پیامکی تأیید می‌شود.`}
    >
      <div
        className="mb-5 flex items-start gap-3 rounded-2xl border p-4"
        style={{ borderColor: 'var(--border-default)', backgroundColor: 'var(--surface-sunken)' }}
      >
        <Building2
          size={18}
          className="mt-0.5 shrink-0"
          style={{ color: 'var(--color-brand-500)' }}
        />
        <div>
          <p className="text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
            {invitation.complexName}
          </p>
          <p className="mt-1 text-xs" style={{ color: 'var(--text-tertiary)' }}>
            {[invitation.unitLabel, `به عنوان ${invitation.roleLabel}`].filter(Boolean).join(' · ')}
          </p>
          {invitation.expiresAt && (
            <p className="mt-1 text-xs" style={{ color: 'var(--text-tertiary)' }}>
              معتبر تا {invitation.expiresAt}
            </p>
          )}
        </div>
      </div>

      <div className="flex flex-col gap-2">
        <Link
          to="/auth?tab=register"
          replace
          className="block rounded-xl py-3 text-center text-sm font-bold text-white"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          ثبت‌نام با همین شماره
        </Link>
        <Link
          to="/auth"
          replace
          className="block rounded-xl border py-3 text-center text-sm font-semibold"
          style={{ borderColor: 'var(--border-default)', color: 'var(--text-primary)' }}
        >
          حساب دارم؛ ورود
        </Link>
      </div>
    </AuthScreen>
  )
}
