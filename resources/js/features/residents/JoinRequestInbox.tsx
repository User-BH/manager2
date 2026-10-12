import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Check, UserPlus, X } from 'lucide-react'
import { api } from '@/shared/lib/api'
import { queryKeys } from '@/shared/lib/queryKeys'
import { useAction } from '@/shared/hooks/useAction'
import { toastError } from '@/shared/lib/alert'

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
 *
 * F6 (مرحلهٔ ۹ · فاز ۶): تأیید **واحد** هم می‌خواهد. پیش از این عضوِ بی‌واحد
 * ساخته می‌شد؛ حالا سرور بی واحد رد می‌کند و اینجا پیش از ارسال گفته می‌شود.
 */
export function JoinRequestInbox({ units }: { units: { id: number; unit_number: string }[] }) {
  const { run, pendingKey } = useAction()
  // واحدِ انتخاب‌شده برای هر درخواست
  const [unitFor, setUnitFor] = useState<Record<number, string>>({})

  const { data } = useQuery({
    queryKey: queryKeys.joinRequests.all(),
    queryFn: ({ signal }) => api<{ data: JoinRequest[] }>('/join-requests', { signal }),
  })

  const respond = (id: number, action: 'approve' | 'reject', role?: 'owner' | 'tenant') => {
    const unitId = unitFor[id]
    if (action === 'approve' && !unitId) {
      toastError('برای تأیید، واحدِ این عضو را انتخاب کنید.')
      return
    }

    return run(
      () =>
        api(`/join-requests/${id}/${action}`, {
          method: 'POST',
          body: action === 'approve' ? { role, unit_id: Number(unitId) } : undefined,
        }),
      {
        key: id,
        success: action === 'approve' ? 'کاربر به مجتمع اضافه شد.' : 'درخواست رد شد.',
        invalidate: [queryKeys.joinRequests.all(), queryKeys.residents.all()],
      },
    )
  }

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
        درخواست‌های عضویت ({data.data.length})
      </p>
      {/* تفاوتِ دو مسیرِ ورود در رابطِ مدیر (مرحلهٔ ۱۰ · فاز ۵؛ بندِ ۲۵) */}
      <p className="-mt-1 text-xs" style={{ color: 'var(--text-tertiary)' }}>
        از QR ِ عمومی یا شماره‌ی مدیر؛ تا تأییدِ شما عضو نیستند.
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

          <div className="flex flex-wrap items-center gap-2">
            <select
              aria-label={`واحدِ ${request.name ?? 'متقاضی'}`}
              value={unitFor[request.id] ?? ''}
              onChange={(event) =>
                setUnitFor((current) => ({ ...current, [request.id]: event.target.value }))
              }
              className="field rounded-lg px-2 py-1.5 text-xs"
            >
              <option value="">انتخاب واحد</option>
              {units.map((unit) => (
                <option key={unit.id} value={unit.id}>
                  واحد {unit.unit_number}
                </option>
              ))}
            </select>
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
