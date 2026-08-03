import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FileText, Loader2, Lock, Send, UserCog } from 'lucide-react'
import { api } from '@/shared/lib/api'
import { alertError, toastSuccess } from '@/shared/lib/alert'
import { errorMessage } from '@/shared/lib/queryClient'
import { queryKeys } from '@/shared/lib/queryKeys'
import { ErrorState, InlineSpinner } from '@/shared/ui/PageState'
import { cn } from '@/shared/lib/cn'
import { StatusBadge } from './StatusBadge'
import { availableMoves, type Option, type RequestStatus, type ServiceRequest } from './types'

/**
 * پرونده‌ی یک درخواست (R25).
 *
 * سه کارِ متفاوت در یک صفحه جمع شده‌اند چون در عمل پشت سرِ هم انجام
 * می‌شوند: خواندنِ شرح، گفت‌وگو، و تغییرِ وضعیت. جداکردنشان یعنی مدیر برای
 * هر درخواست سه بار جابه‌جا شود.
 *
 * دکمه‌های وضعیت از `availableMoves` می‌آیند که **فقط برای رابط** است؛
 * قاعده‌ی واقعی سمتِ سرور اعمال می‌شود.
 */
export function RequestDetail({
  id,
  assignables,
  priorities,
  onChanged,
}: {
  id: number
  assignables: { id: number; name: string; role: string }[]
  priorities: Option[]
  onChanged: () => void
}) {
  const queryClient = useQueryClient()

  const [body, setBody] = useState('')
  const [isInternal, setIsInternal] = useState(false)

  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.serviceRequests.detail(id),
    queryFn: ({ signal }) =>
      api<{ request: ServiceRequest }>(`/service-requests/${id}`, { signal }),
  })

  const request = data?.request

  /** هر تغییری هم پرونده و هم فهرست را تازه می‌کند؛ شمارنده‌ها آنجا هستند. */
  const refresh = (updated: ServiceRequest) => {
    queryClient.setQueryData(queryKeys.serviceRequests.detail(id), { request: updated })
    void queryClient.invalidateQueries({ queryKey: queryKeys.serviceRequests.all() })
    onChanged()
  }

  const move = useMutation({
    mutationFn: (status: RequestStatus) =>
      api<{ request: ServiceRequest }>(`/service-requests/${id}/status`, {
        method: 'PATCH',
        body: { status, note: body.trim() || undefined },
      }),
    onSuccess: ({ request: updated }) => {
      setBody('')
      refresh(updated)
      toastSuccess('وضعیت درخواست تغییر کرد.')
    },
    onError: (err) => alertError(err, 'تغییر وضعیت ممکن نشد.'),
  })

  const assign = useMutation({
    mutationFn: (assignedTo: number) =>
      api<{ request: ServiceRequest }>(`/service-requests/${id}/assign`, {
        method: 'PATCH',
        body: { assigned_to: assignedTo },
      }),
    onSuccess: ({ request: updated }) => {
      refresh(updated)
      toastSuccess('مسئول پیگیری تعیین شد.')
    },
    onError: (err) => alertError(err, 'واگذاری ممکن نشد.'),
  })

  const setPriority = useMutation({
    mutationFn: (priority: string) =>
      api<{ request: ServiceRequest }>(`/service-requests/${id}/priority`, {
        method: 'PATCH',
        body: { priority },
      }),
    onSuccess: ({ request: updated }) => refresh(updated),
    onError: (err) => alertError(err, 'تغییر فوریت ممکن نشد.'),
  })

  const comment = useMutation({
    mutationFn: () =>
      api<{ request: ServiceRequest }>(`/service-requests/${id}/comments`, {
        method: 'POST',
        body: { body: body.trim(), is_internal: isInternal },
      }),
    onSuccess: ({ request: updated }) => {
      setBody('')
      refresh(updated)
    },
    onError: (err) => alertError(err, 'ارسال پیام ممکن نشد.'),
  })

  if (isLoading) return <InlineSpinner />
  if (error) return <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />
  if (!request) return null

  const { assign: isAdmin, isAssignee, isRequester } = request.can
  const moves = availableMoves(request, isAdmin, isAssignee, isRequester)
  const isFinal = request.status === 'closed' || request.status === 'rejected'

  const inputStyle = {
    backgroundColor: 'var(--surface-sunken)',
    borderColor: 'var(--border-subtle)',
    color: 'var(--text-primary)',
  }

  return (
    <div className="flex flex-col gap-4">
      <header className="flex flex-col gap-2">
        <div className="flex flex-wrap items-center gap-2">
          <h2 className="text-[16px] font-bold" style={{ color: 'var(--text-primary)' }}>
            {request.title}
          </h2>
          <StatusBadge label={request.statusLabel} color={request.statusColor} />
          <StatusBadge label={request.priorityLabel} color={request.priorityColor} />
        </div>

        <p className="text-[12px]" style={{ color: 'var(--text-tertiary)' }}>
          {request.categoryLabel}
          {request.unitLabel && ` · ${request.unitLabel}`}
          {request.requesterName && ` · ${request.requesterName}`}
          {` · ${request.createdAt}`}
        </p>
      </header>

      <p
        className="whitespace-pre-line rounded-xl p-3 text-[13px] leading-6"
        style={{ backgroundColor: 'var(--surface-sunken)', color: 'var(--text-primary)' }}
      >
        {request.description}
      </p>

      {request.attachment && (
        <a
          href={request.attachment.url}
          target="_blank"
          rel="noreferrer"
          className="flex items-center gap-1.5 text-[12px] underline"
          style={{ color: 'var(--color-brand-500)' }}
        >
          <FileText size={13} />
          {request.attachment.name}
        </a>
      )}

      {/* ── واگذاری و فوریت: فقط مدیر ─────────────────────────────────── */}
      {isAdmin && (
        <div className="flex flex-wrap gap-2">
          <label
            className="flex flex-1 items-center gap-2 text-[12px]"
            style={{ color: 'var(--text-secondary)' }}
          >
            <UserCog size={14} />
            <select
              value={request.assignee?.id ?? 0}
              onChange={(event) => assign.mutate(Number(event.target.value))}
              disabled={assign.isPending}
              className="flex-1 rounded-lg border px-2.5 py-1.5 text-[12.5px] outline-none"
              style={inputStyle}
            >
              <option value={0}>مسئولی تعیین نشده</option>
              {assignables.map((person) => (
                <option key={person.id} value={person.id}>
                  {person.name} ({person.role})
                </option>
              ))}
            </select>
          </label>

          <select
            value={request.priority}
            onChange={(event) => setPriority.mutate(event.target.value)}
            disabled={setPriority.isPending}
            className="rounded-lg border px-2.5 py-1.5 text-[12.5px] outline-none"
            style={inputStyle}
          >
            {priorities.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>
      )}

      {/* ── گفت‌وگو ────────────────────────────────────────────────────── */}
      <div className="flex flex-col gap-2">
        {(request.comments ?? []).length === 0 ? (
          <p className="text-[12px]" style={{ color: 'var(--text-tertiary)' }}>
            هنوز پیامی رد و بدل نشده است.
          </p>
        ) : (
          <ul className="flex flex-col gap-2">
            {request.comments?.map((item) => (
              <li
                key={item.id}
                className={cn('rounded-xl px-3 py-2 text-[12.5px]')}
                style={{
                  // یادداشتِ داخلی باید در یک نگاه از پیامِ عادی جدا باشد
                  backgroundColor: item.isInternal
                    ? 'rgba(245,158,11,0.10)'
                    : 'var(--surface-sunken)',
                  color: 'var(--text-primary)',
                }}
              >
                <div
                  className="mb-1 flex items-center gap-2 text-[11px]"
                  style={{ color: 'var(--text-tertiary)' }}
                >
                  <span className="font-semibold">{item.authorName}</span>
                  <span>·</span>
                  <span>{item.sentAt}</span>
                  {item.isInternal && (
                    <span className="flex items-center gap-1">
                      <Lock size={10} />
                      یادداشت داخلی
                    </span>
                  )}
                </div>
                <p className="whitespace-pre-line leading-6">{item.body}</p>
              </li>
            ))}
          </ul>
        )}
      </div>

      {/* ── نوشتن و تغییر وضعیت ────────────────────────────────────────── */}
      {isFinal && !isAdmin ? (
        <p
          className="rounded-xl px-3 py-2 text-[12.5px]"
          style={{ backgroundColor: 'var(--surface-sunken)', color: 'var(--text-secondary)' }}
        >
          این درخواست بسته شده است.
        </p>
      ) : (
        <div className="flex flex-col gap-2">
          <textarea
            value={body}
            onChange={(event) => setBody(event.target.value)}
            rows={2}
            maxLength={2000}
            placeholder="پیام یا توضیح…"
            className="resize-none rounded-xl border px-3 py-2 text-[13px] outline-none"
            style={inputStyle}
          />

          <div className="flex flex-wrap items-center gap-2">
            <button
              type="button"
              onClick={() => comment.mutate()}
              disabled={body.trim() === '' || comment.isPending}
              className="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[12px] font-bold text-white disabled:opacity-50"
              style={{ backgroundColor: 'var(--color-brand-500)' }}
            >
              {comment.isPending ? (
                <Loader2 size={13} className="animate-spin" />
              ) : (
                <Send size={13} />
              )}
              ارسال
            </button>

            {request.can.noteInternally && (
              <label
                className="flex items-center gap-1.5 text-[12px]"
                style={{ color: 'var(--text-secondary)' }}
              >
                <input
                  type="checkbox"
                  checked={isInternal}
                  onChange={(event) => setIsInternal(event.target.checked)}
                />
                یادداشت داخلی (ساکن نمی‌بیند)
              </label>
            )}

            <span className="flex-1" />

            {moves.map((option) => (
              <button
                key={option.status}
                type="button"
                onClick={() => move.mutate(option.status)}
                disabled={move.isPending}
                className="rounded-lg border px-3 py-1.5 text-[12px] disabled:opacity-50"
                style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
              >
                {option.label}
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
