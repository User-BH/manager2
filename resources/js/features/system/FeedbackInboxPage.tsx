import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Archive, CheckCheck, MessageSquareHeart, Phone, RotateCcw } from 'lucide-react'
import { Card } from '@/shared/ui/Card'
import { EmptyState, ErrorState } from '@/shared/ui/PageState'
import { TableSkeleton } from '@/shared/ui/Skeleton'
import { Pager, type PageMeta } from '@/shared/ui/Pager'
import { useApiAction } from '@/shared/hooks/useAction'
import { useDocumentTitle } from '@/shared/hooks'
import { api } from '@/shared/lib/api'
import { formatJalaliDateTime, formatNumber } from '@/shared/lib/format'
import { toAsciiDigits } from '@/shared/lib/inputFilters'
import { queryKeys } from '@/shared/lib/queryKeys'

type FeedbackStatus = 'new' | 'read' | 'archived'

interface FeedbackRow {
  id: number
  category: string
  categoryLabel: string
  message: string
  name: string | null
  phone: string | null
  status: FeedbackStatus
  statusLabel: string
  createdAt: string | null
}

interface FeedbackResponse {
  data: FeedbackRow[]
  meta: PageMeta
  counts: { new: number }
}

const FILTERS: { value: FeedbackStatus | ''; label: string }[] = [
  { value: 'new', label: 'تازه' },
  { value: 'read', label: 'خوانده‌شده' },
  { value: 'archived', label: 'بایگانی' },
  { value: '', label: 'همه' },
]

/**
 * صفِ بازخوردهای صفحه‌ی پشتیبانی — فقط ادمینِ کل (مرحلهٔ ۷ · فاز ۴).
 *
 * ⚠️ متنِ پیام از بیرون آمده و **همیشه متن** رندر می‌شود (React escape
 * می‌کند؛ هیچ `dangerouslySetInnerHTML`ی اینجا نیست). سطرهای تازه با
 * `whitespace-pre-line` نگه داشته می‌شوند تا پیامِ چندبندی خوانا بماند.
 */
export function FeedbackInboxPage() {
  useDocumentTitle('بازخوردها')
  const [status, setStatus] = useState<FeedbackStatus | ''>('new')
  const [page, setPage] = useState(1)
  const { call, isPending } = useApiAction()

  const params = { status: status || undefined, page }
  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.system.feedback(params),
    queryFn: ({ signal }) => {
      const query = new URLSearchParams({ page: String(page) })
      if (status) query.set('status', status)
      return api<FeedbackResponse>(`/system/feedback?${query.toString()}`, { signal })
    },
  })

  function move(item: FeedbackRow, to: FeedbackStatus, success: string) {
    void call(
      `/system/feedback/${item.id}`,
      { method: 'PATCH', body: { status: to } },
      { key: item.id, success, invalidate: [queryKeys.system.all()] },
    )
  }

  return (
    <div className="flex flex-col gap-5">
      <header>
        <h1
          className="flex items-center gap-2 text-xl font-extrabold"
          style={{ color: 'var(--text-primary)' }}
        >
          <MessageSquareHeart size={22} style={{ color: 'var(--color-brand-500)' }} />
          بازخوردها
        </h1>
        <p className="mt-1 text-body" style={{ color: 'var(--text-tertiary)' }}>
          پیام‌های فرمِ بازخوردِ صفحه‌ی پشتیبانی
          {data ? ` — ${formatNumber(data.counts.new)} پیامِ تازه` : ''}
        </p>
      </header>

      <div className="flex flex-wrap gap-2" role="tablist" aria-label="وضعیت">
        {FILTERS.map((filter) => {
          const active = status === filter.value
          return (
            <button
              key={filter.label}
              type="button"
              role="tab"
              aria-selected={active}
              onClick={() => {
                setStatus(filter.value)
                setPage(1)
              }}
              className="rounded-xl border px-3.5 py-2 text-small font-semibold"
              style={{
                borderColor: active ? 'var(--color-brand-500)' : 'var(--border-subtle)',
                color: active ? 'var(--color-brand-600)' : 'var(--text-secondary)',
                backgroundColor: active
                  ? 'color-mix(in srgb, var(--color-brand-500) 10%, transparent)'
                  : 'transparent',
              }}
            >
              {filter.label}
            </button>
          )
        })}
      </div>

      {isLoading && <TableSkeleton rows={4} columns={3} />}
      {error && <ErrorState error={error} onRetry={() => void refetch()} />}

      {data && data.data.length === 0 && (
        <EmptyState
          message="پیامی در این بخش نیست"
          hint="بازخوردهایی که از صفحه‌ی پشتیبانی فرستاده شوند اینجا می‌آیند."
        />
      )}

      {data && data.data.length > 0 && (
        <>
          <ul className="flex flex-col gap-3">
            {data.data.map((item) => (
              <li key={item.id}>
                <Card>
                  <div className="flex flex-wrap items-center gap-2 text-small">
                    <span
                      className="rounded-full px-2.5 py-0.5 font-bold"
                      style={{
                        backgroundColor: 'var(--tone-brand-bg)',
                        color: 'var(--tone-brand-fg)',
                      }}
                    >
                      {item.categoryLabel}
                    </span>
                    <span style={{ color: 'var(--text-tertiary)' }}>
                      {item.createdAt ? formatJalaliDateTime(new Date(item.createdAt)) : ''}
                    </span>
                    <span className="mr-auto" style={{ color: 'var(--text-tertiary)' }}>
                      {item.statusLabel}
                    </span>
                  </div>

                  <p
                    dir="auto"
                    className="mt-3 whitespace-pre-line break-words text-body leading-7"
                    style={{ color: 'var(--text-primary)' }}
                  >
                    {item.message}
                  </p>

                  {(item.name || item.phone) && (
                    <p
                      className="mt-3 flex flex-wrap items-center gap-3 text-small"
                      style={{ color: 'var(--text-secondary)' }}
                    >
                      {item.name && <span>{item.name}</span>}
                      {item.phone && (
                        <a
                          href={`tel:${toAsciiDigits(item.phone)}`}
                          className="inline-flex items-center gap-1 font-semibold"
                          style={{ color: 'var(--color-brand-600)' }}
                          dir="ltr"
                        >
                          <Phone size={13} />
                          {item.phone}
                        </a>
                      )}
                    </p>
                  )}

                  <div className="mt-4 flex flex-wrap gap-2">
                    {item.status === 'new' && (
                      <button
                        type="button"
                        disabled={isPending(item.id)}
                        onClick={() => move(item, 'read', 'خوانده‌شده علامت خورد.')}
                        className="inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-small font-semibold"
                        style={{
                          borderColor: 'var(--border-default)',
                          color: 'var(--text-secondary)',
                        }}
                      >
                        <CheckCheck size={14} />
                        خوانده شد
                      </button>
                    )}
                    {item.status !== 'archived' ? (
                      <button
                        type="button"
                        disabled={isPending(item.id)}
                        onClick={() => move(item, 'archived', 'بایگانی شد.')}
                        className="inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-small font-semibold"
                        style={{
                          borderColor: 'var(--border-default)',
                          color: 'var(--text-secondary)',
                        }}
                      >
                        <Archive size={14} />
                        بایگانی
                      </button>
                    ) : (
                      <button
                        type="button"
                        disabled={isPending(item.id)}
                        onClick={() => move(item, 'read', 'از بایگانی برگشت.')}
                        className="inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-small font-semibold"
                        style={{
                          borderColor: 'var(--border-default)',
                          color: 'var(--text-secondary)',
                        }}
                      >
                        <RotateCcw size={14} />
                        برگرداندن
                      </button>
                    )}
                  </div>
                </Card>
              </li>
            ))}
          </ul>
          <Pager meta={data.meta} onChange={setPage} noun="پیام" />
        </>
      )}
    </div>
  )
}
