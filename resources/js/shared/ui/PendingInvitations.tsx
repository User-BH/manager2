import { useQuery } from '@tanstack/react-query'
import { Hourglass, X } from 'lucide-react'
import { api } from '@/shared/lib/api'
import { queryKeys } from '@/shared/lib/queryKeys'
import { useAction } from '@/shared/hooks/useAction'

export interface SentInvitation {
  id: number
  phone: string | null
  roleLabel: string
  unitLabel: string | null
  expired: boolean
  expiresAt: string | null
}

/**
 * دعوت‌های در انتظارِ همین مجتمع، با ابطال (مرحلهٔ ۹ · فاز ۶، F7).
 *
 * وقتی دعوتی نیست چیزی رندر نمی‌شود — همان قاعده‌ی `JoinRequestInbox` — تا
 * صفحه‌ی ساکنین و مدیران شلوغ نشود.
 */
export function PendingInvitations({ kind }: { kind: 'residents' | 'managers' }) {
  const { run, pendingKey } = useAction()

  const { data } = useQuery({
    queryKey: queryKeys.sentInvitations.list(kind),
    queryFn: ({ signal }) =>
      api<{ data: SentInvitation[] }>(`/sent-invitations?kind=${kind}`, { signal }),
  })

  const revoke = (invitation: SentInvitation) =>
    run(() => api(`/sent-invitations/${invitation.id}`, { method: 'DELETE' }), {
      key: invitation.id,
      confirm: {
        title: 'دعوت باطل شود؟',
        text: 'پیوندِ این دعوت دیگر کار نمی‌کند. برای دعوتِ دوباره، همان شماره را از نو اضافه کنید.',
        confirmLabel: 'باطل کن',
        danger: true,
      },
      success: 'دعوت باطل شد.',
      invalidate: [queryKeys.sentInvitations.list(kind)],
    })

  if (!data?.data.length) return null

  return (
    <section
      className="flex flex-col gap-3 rounded-2xl border p-4"
      style={{ borderColor: 'var(--border-default)', backgroundColor: 'var(--surface-base)' }}
    >
      <p
        className="flex items-center gap-2 text-sm font-bold"
        style={{ color: 'var(--text-primary)' }}
      >
        <Hourglass size={16} style={{ color: 'var(--color-brand-500)' }} />
        دعوت‌های مستقیم در انتظار ({data.data.length})
      </p>
      {/* تفاوتِ دو مسیرِ ورود در رابطِ مدیر (مرحلهٔ ۱۰ · فاز ۵؛ بندِ ۲۵) */}
      <p className="-mt-1 text-xs" style={{ color: 'var(--text-tertiary)' }}>
        پیوندِ شخصی به یک شماره؛ با پذیرش، بی تأییدِ دوباره عضو می‌شوند.
      </p>

      {data.data.map((invitation) => (
        <div
          key={invitation.id}
          className="flex flex-wrap items-center justify-between gap-3 rounded-xl px-3.5 py-3"
          style={{ backgroundColor: 'var(--surface-sunken)' }}
        >
          <div>
            <p
              className="text-sm font-semibold tabular-nums"
              dir="ltr"
              style={{ color: 'var(--text-primary)' }}
            >
              {invitation.phone}
            </p>
            <p className="mt-0.5 text-xs" style={{ color: 'var(--text-tertiary)' }}>
              {[
                invitation.roleLabel,
                invitation.unitLabel,
                invitation.expired
                  ? 'منقضی شد — دوباره بفرستید'
                  : `تا ${invitation.expiresAt ?? '—'}`,
              ]
                .filter(Boolean)
                .join(' · ')}
            </p>
          </div>

          {!invitation.expired && (
            <button
              type="button"
              onClick={() => void revoke(invitation)}
              disabled={pendingKey !== null}
              className="flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold disabled:opacity-60"
              style={{ borderColor: 'var(--border-default)', color: 'var(--text-secondary)' }}
            >
              <X size={13} />
              ابطال
            </button>
          )}
        </div>
      ))}
    </section>
  )
}
