import { useQuery } from '@tanstack/react-query'
import { Check, UserPlus, X } from 'lucide-react'
import { api } from '@/shared/lib/api'
import { queryKeys } from '@/shared/lib/queryKeys'
import { useAction } from '@/shared/hooks/useAction'

interface JoinRequest {
  id: number
  name: string | null
  phone: string | null
  createdAt: string | null
}

/**
 * صندوقِ درخواست‌های پیوستن (R21b).
 *
 * ساکنی که شماره‌ی مدیر را وارد کرده اینجا ظاهر می‌شود. مدیر **نام و شماره**
 * را می‌بیند — همان چیزی که برای شناختنش لازم است — و نقشش را هنگام تایید
 * انتخاب می‌کند.
 *
 * وقتی درخواستی نباشد چیزی رندر نمی‌شود، تا صفحه‌ی ساکنین شلوغ نشود.
 */
export function JoinRequestInbox() {
  const { run, pendingKey } = useAction()

  const { data } = useQuery({
    queryKey: queryKeys.joinRequests.all(),
    queryFn: ({ signal }) => api<{ data: JoinRequest[] }>('/join-requests', { signal }),
  })

  const respond = (id: number, action: 'approve' | 'reject', role?: 'owner' | 'tenant') =>
    run(
      () =>
        api(`/join-requests/${id}/${action}`, {
          method: 'POST',
          body: role ? { role } : undefined,
        }),
      {
        key: id,
        success: action === 'approve' ? 'کاربر به مجتمع اضافه شد.' : 'درخواست رد شد.',
        invalidate: [queryKeys.joinRequests.all(), queryKeys.residents.all()],
      },
    )

  if (!data?.data.length) return null

  return (
    <section
      className="flex flex-col gap-3 rounded-2xl border p-4"
      style={{ borderColor: 'var(--color-brand-500)', backgroundColor: 'var(--surface-base)' }}
    >
      <p
        className="flex items-center gap-2 text-sm font-bold"
        style={{ color: 'var(--text-primary)' }}
      >
        <UserPlus size={16} style={{ color: 'var(--color-brand-500)' }} />
        درخواست‌های پیوستن ({data.data.length})
      </p>

      {data.data.map((request) => (
        <div
          key={request.id}
          className="flex flex-wrap items-center justify-between gap-3 rounded-xl px-3.5 py-3"
          style={{ backgroundColor: 'var(--surface-sunken)' }}
        >
          <div>
            <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
              {request.name}
            </p>
            <p className="mt-0.5 text-xs tabular-nums" style={{ color: 'var(--text-tertiary)' }}>
              {request.phone}
            </p>
          </div>

          <div className="flex flex-wrap gap-2">
            <button
              onClick={() => void respond(request.id, 'approve', 'owner')}
              disabled={pendingKey !== null}
              className="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-bold text-white disabled:opacity-60"
              style={{ backgroundColor: 'var(--color-brand-500)' }}
            >
              <Check size={13} />
              تایید به‌عنوان مالک
            </button>
            <button
              onClick={() => void respond(request.id, 'approve', 'tenant')}
              disabled={pendingKey !== null}
              className="rounded-lg border px-3 py-1.5 text-xs font-semibold disabled:opacity-60"
              style={{ borderColor: 'var(--border-default)', color: 'var(--text-primary)' }}
            >
              تایید به‌عنوان مستاجر
            </button>
            <button
              onClick={() => void respond(request.id, 'reject')}
              disabled={pendingKey !== null}
              className="flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold disabled:opacity-60"
              style={{ borderColor: 'var(--border-default)', color: 'var(--text-secondary)' }}
            >
              <X size={13} />
              رد
            </button>
          </div>
        </div>
      ))}
    </section>
  )
}
