import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Paperclip, Plus, UserCog } from 'lucide-react'
import { api } from '@/shared/lib/api'
import { errorMessage } from '@/shared/lib/queryClient'
import { queryKeys } from '@/shared/lib/queryKeys'
import { useDocumentTitle } from '@/shared/hooks'
import { Card } from '@/shared/ui/Card'
import { Modal } from '@/shared/ui/Modal'
import { EmptyState, ErrorState, InlineSpinner } from '@/shared/ui/PageState'
import { cn } from '@/shared/lib/cn'
import { StatusBadge } from './StatusBadge'
import { NewRequestModal } from './NewRequestModal'
import { RequestDetail } from './RequestDetail'
import type { RequestListResponse, ServiceRequest } from './types'

/**
 * فهرستِ درخواست‌ها (R25).
 *
 * یک صفحه برای هر سه نقش، چون کارشان یکی است و فقط دامنه‌ی دیدشان فرق
 * دارد — همان قاعده‌ای که `visibleTo` سمتِ سرور اعمال می‌کند. ساختنِ سه
 * صفحه‌ی جدا یعنی سه جا باید یادمان بماند فیلترِ تازه را اضافه کنیم.
 */
export function ServiceRequestsPage() {
  const [status, setStatus] = useState('open')
  const [category, setCategory] = useState('')
  const [mine, setMine] = useState(false)
  const [openId, setOpenId] = useState<number | null>(null)
  const [isCreating, setIsCreating] = useState(false)

  useDocumentTitle('درخواست‌ها')

  const params = { status, category: category || undefined, mine: mine || undefined }

  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.serviceRequests.list(params),
    queryFn: ({ signal }) => {
      const query = new URLSearchParams()
      if (status) query.set('status', status)
      if (category) query.set('category', category)
      if (mine) query.set('mine', '1')

      return api<RequestListResponse>(`/service-requests?${query.toString()}`, { signal })
    },
  })

  if (isLoading) return <InlineSpinner />
  if (error) return <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />
  if (!data) return null

  /*
   * تبِ «باز» جمعِ سه وضعیت است و نه یک وضعیت — پیش‌فرضِ مفیدِ مدیر، چون
   * چیزی که کارِ نکرده دارد همان است.
   */
  const tabs = [
    { value: 'open', label: 'باز', count: data.counts.open ?? 0 },
    ...data.statuses.map((option) => ({
      value: option.value,
      label: option.label,
      count: data.counts[option.value] ?? 0,
    })),
  ]

  return (
    <div className="flex flex-col gap-5">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
            درخواست‌ها
          </h1>
          <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
            {data.isAdmin
              ? 'درخواست‌های ساکنین، واگذاری به مسئول و پیگیری وضعیت'
              : 'درخواست‌های شما و وضعیت پیگیری‌شان'}
          </p>
        </div>

        <button
          type="button"
          onClick={() => setIsCreating(true)}
          className="flex items-center gap-1.5 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          <Plus size={15} />
          درخواست جدید
        </button>
      </header>

      {/* ── فیلترها ────────────────────────────────────────────────────── */}
      <div className="flex flex-wrap items-center gap-2">
        {tabs.map((tab) => (
          <button
            key={tab.value}
            type="button"
            onClick={() => setStatus(tab.value)}
            className={cn(
              'rounded-full border px-3 py-1.5 text-[12px] transition-colors',
              status === tab.value && 'font-bold',
            )}
            style={{
              borderColor: status === tab.value ? 'var(--color-brand-500)' : 'var(--border-subtle)',
              color: status === tab.value ? 'var(--color-brand-500)' : 'var(--text-secondary)',
            }}
          >
            {tab.label}
            {tab.count > 0 && <span className="mr-1 tabular-nums">({tab.count})</span>}
          </button>
        ))}

        <span className="flex-1" />

        <select
          value={category}
          onChange={(event) => setCategory(event.target.value)}
          className="rounded-lg border px-2.5 py-1.5 text-[12.5px] outline-none"
          style={{
            backgroundColor: 'var(--surface-sunken)',
            borderColor: 'var(--border-subtle)',
            color: 'var(--text-primary)',
          }}
        >
          <option value="">همه‌ی دسته‌ها</option>
          {data.categories.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>

        {/* «فقط مالِ من» برای مسئول: فهرستِ کاملِ ساختمان برایش نویز است */}
        <label
          className="flex items-center gap-1.5 text-[12px]"
          style={{ color: 'var(--text-secondary)' }}
        >
          <input type="checkbox" checked={mine} onChange={(e) => setMine(e.target.checked)} />
          واگذارشده به من
        </label>
      </div>

      {/* ── فهرست ──────────────────────────────────────────────────────── */}
      {data.requests.length === 0 ? (
        <EmptyState
          message="درخواستی در این فیلتر نیست"
          hint="با تغییر فیلترها یا ثبت درخواست تازه شروع کنید."
        />
      ) : (
        <ul className="flex flex-col gap-2">
          {data.requests.map((request: ServiceRequest) => (
            <li key={request.id}>
              <button
                type="button"
                onClick={() => setOpenId(request.id)}
                className="w-full rounded-2xl border p-4 text-right transition-colors"
                style={{
                  borderColor: 'var(--border-subtle)',
                  backgroundColor: 'var(--surface-base)',
                }}
              >
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-bold" style={{ color: 'var(--text-primary)' }}>
                    {request.title}
                  </span>
                  <StatusBadge label={request.statusLabel} color={request.statusColor} />
                  {request.priority !== 'normal' && (
                    <StatusBadge label={request.priorityLabel} color={request.priorityColor} />
                  )}
                  {request.attachment && (
                    <Paperclip size={12} style={{ color: 'var(--text-tertiary)' }} />
                  )}
                </div>

                <p
                  className="mt-1.5 flex flex-wrap items-center gap-x-2 text-[11.5px]"
                  style={{ color: 'var(--text-tertiary)' }}
                >
                  <span>{request.categoryLabel}</span>
                  {request.unitLabel && <span>· {request.unitLabel}</span>}
                  <span>· {request.createdAt}</span>
                  {request.assignee ? (
                    <span className="flex items-center gap-1">
                      · <UserCog size={11} />
                      {request.assignee.name}
                    </span>
                  ) : (
                    request.isOpen && <span>· مسئولی تعیین نشده</span>
                  )}
                </p>
              </button>
            </li>
          ))}
        </ul>
      )}

      {data.lastPage > 1 && (
        <p className="text-center text-[12px]" style={{ color: 'var(--text-tertiary)' }}>
          {data.total} درخواست — صفحه‌ی {data.currentPage} از {data.lastPage}
        </p>
      )}

      {isCreating && (
        <NewRequestModal
          categories={data.categories}
          onClose={() => setIsCreating(false)}
          onCreated={() => {
            setIsCreating(false)
            void refetch()
          }}
        />
      )}

      {openId !== null && (
        <Modal open title="پرونده‌ی درخواست" onClose={() => setOpenId(null)}>
          <Card className="border-0 p-0">
            <RequestDetail
              id={openId}
              assignables={data.assignables}
              priorities={data.priorities}
              onChanged={() => void refetch()}
            />
          </Card>
        </Modal>
      )}
    </div>
  )
}
