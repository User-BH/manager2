import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Building2, CheckCircle2, Loader2 } from 'lucide-react'
import { AuthScreen } from './components/AuthScreen'
import { forgetJoinCode, joinCode } from './joinCode'
import { api, ApiError } from '@/shared/lib/api'
import { useDocumentTitle } from '@/shared/hooks'
import type { CurrentUser } from '@/shared/types'

type State =
  | { kind: 'loading' }
  | { kind: 'invalid' }
  | { kind: 'ready'; complexName: string; signedIn: boolean }
  | { kind: 'sent'; message: string }

/**
 * صفحه‌ی پیوستن با QR ِ عمومیِ مجتمع (مرحلهٔ ۱۰ · فاز ۵؛ بندهای ۲۲ و ۲۳ ِ فایلِ معماری).
 *
 *   اسکن ← این صفحه (نامِ مجتمع) ← ثبت‌نام/ورود ← «ارسالِ درخواستِ عضویت» ← تأییدِ مدیر ← عضویت
 *
 * ⚠️ «صرفِ اسکنِ QR به معنی عضویت نیست»: این صفحه هیچ دسترسی‌ای نمی‌دهد. نامِ مجتمع از سرور می‌آید و درخواست
 * با کلیکِ خودِ کاربر از `join-requests` می‌رود؛ تا تأییدِ مدیر حساب در هیچ مجتمعی عضو نمی‌شود.
 */
export function JoinPage() {
  useDocumentTitle('عضویت در مجتمع')

  const [code] = useState(joinCode)
  const [state, setState] = useState<State>(() =>
    code ? { kind: 'loading' } : { kind: 'invalid' },
  )
  const [sending, setSending] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!code) {
      forgetJoinCode()
      return
    }

    const controller = new AbortController()

    Promise.all([
      api<{ complexName: string }>('/join-link/preview', {
        method: 'POST',
        body: { code },
        signal: controller.signal,
      }),
      api<{ user: CurrentUser | null }>('/me', { signal: controller.signal }),
    ])
      .then(([preview, me]) =>
        setState({ kind: 'ready', complexName: preview.complexName, signedIn: me.user !== null }),
      )
      .catch(() => {
        if (controller.signal.aborted) return
        forgetJoinCode()
        setState({ kind: 'invalid' })
      })

    return () => controller.abort()
  }, [code])

  async function send() {
    setSending(true)
    setError(null)

    try {
      const { message } = await api<{ message: string }>('/join-requests', {
        method: 'POST',
        body: { code },
      })
      forgetJoinCode()
      setState({ kind: 'sent', message })
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'ارتباط با سرور برقرار نشد.')
    } finally {
      setSending(false)
    }
  }

  if (state.kind === 'loading') {
    return (
      <AuthScreen title="عضویت در مجتمع">
        <p
          className="flex items-center justify-center gap-2 text-sm"
          style={{ color: 'var(--text-secondary)' }}
        >
          <Loader2 size={16} className="animate-spin" />
          در حال بررسیِ QR…
        </p>
      </AuthScreen>
    )
  }

  if (state.kind === 'invalid') {
    return (
      <AuthScreen
        title="این QR معتبر نیست"
        subtitle="شاید مدیرِ مجتمع QR را عوض یا خاموش کرده است. QR ِ تازه را از مدیر بخواهید."
      >
        <a
          href="/dashboard"
          className="block rounded-xl py-3 text-center text-sm font-bold text-white"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          رفتن به ساکنا
        </a>
      </AuthScreen>
    )
  }

  if (state.kind === 'sent') {
    return (
      <AuthScreen title="درخواست فرستاده شد" subtitle={state.message}>
        <p
          className="mb-4 flex items-start gap-2 rounded-xl px-3 py-2.5 text-xs leading-6"
          style={{ backgroundColor: 'var(--surface-sunken)', color: 'var(--text-secondary)' }}
        >
          <CheckCircle2
            size={15}
            className="mt-1 shrink-0"
            style={{ color: 'var(--state-success)' }}
          />
          تا تأییدِ مدیر عضوِ مجتمع نیستید؛ پس از تأیید، داشبوردِ واحدتان باز می‌شود.
        </p>
        <a
          href="/dashboard"
          className="block rounded-xl py-3 text-center text-sm font-bold text-white"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          رفتن به داشبورد
        </a>
      </AuthScreen>
    )
  }

  return (
    <AuthScreen
      title="عضویت در مجتمع"
      subtitle="اسکنِ QR به‌تنهایی عضویت نیست: درخواست می‌فرستید و مدیرِ مجتمع آن را تأیید می‌کند."
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
        <p className="text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
          {state.complexName}
        </p>
      </div>

      {error && (
        <p
          role="alert"
          className="mb-3 text-center text-xs"
          style={{ color: 'var(--color-danger)' }}
        >
          {error}
        </p>
      )}

      {state.signedIn ? (
        <div className="flex flex-col gap-2">
          <button
            type="button"
            onClick={() => void send()}
            disabled={sending}
            className="flex items-center justify-center gap-2 rounded-xl py-3 text-sm font-bold text-white disabled:opacity-60"
            style={{ backgroundColor: 'var(--color-brand-500)' }}
          >
            {sending && <Loader2 size={15} className="animate-spin" />}
            ارسالِ درخواستِ عضویت
          </button>
          <a
            href="/dashboard"
            onClick={() => forgetJoinCode()}
            className="block rounded-xl border py-3 text-center text-sm font-semibold"
            style={{ borderColor: 'var(--border-default)', color: 'var(--text-primary)' }}
          >
            فعلاً نه
          </a>
        </div>
      ) : (
        <div className="flex flex-col gap-2">
          <Link
            to="/auth?tab=register"
            className="block rounded-xl py-3 text-center text-sm font-bold text-white"
            style={{ backgroundColor: 'var(--color-brand-500)' }}
          >
            ثبت‌نام
          </Link>
          <Link
            to="/auth"
            className="block rounded-xl border py-3 text-center text-sm font-semibold"
            style={{ borderColor: 'var(--border-default)', color: 'var(--text-primary)' }}
          >
            حساب دارم؛ ورود
          </Link>
        </div>
      )}
    </AuthScreen>
  )
}
