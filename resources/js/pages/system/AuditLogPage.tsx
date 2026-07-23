import { useState } from 'react'
import { motion } from 'framer-motion'
import { ChevronDown, ChevronUp, ScrollText } from 'lucide-react'
import { Card } from '@/components/ui/Card'
import { SelectField } from '@/components/ui/Field'
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/PageState'
import { useApi } from '@/hooks/useApi'
import { useDocumentTitle } from '@/hooks'

interface AuditEntry {
  id: number
  action: string
  actionLabel: string
  description: string | null
  userName: string
  userPhone: string | null
  ip: string | null
  properties: Record<string, unknown> | null
  at: string
}

interface AuditResponse {
  data: AuditEntry[]
  meta: { currentPage: number; lastPage: number; total: number }
  actions: { value: string; label: string }[]
}

/** رویدادهایی که برگشت‌ناپذیرند و باید در فهرست برجسته باشند. */
const DESTRUCTIVE = ['deleted', 'restored', 'deactivated', 'rejected', 'blocked']

/**
 * لاگ فعالیت.
 *
 * جدولش از ابتدا پر می‌شد ولی هیچ راهی برای دیدنش نبود، پس عملاً وجود نداشت.
 */
export function AuditLogPage() {
  useDocumentTitle('لاگ فعالیت')

  const [action, setAction] = useState('')
  const [page, setPage] = useState(1)
  const [expanded, setExpanded] = useState<number | null>(null)

  const query = new URLSearchParams()
  if (action) query.set('action', action)
  if (page > 1) query.set('page', String(page))

  const { data, error, isLoading, reload } = useApi<AuditResponse>(
    `/system/audit-logs${query.toString() ? `?${query}` : ''}`,
    [action, page],
  )

  if (isLoading) return <LoadingState rows={5} />
  if (error) return <ErrorState message={error} onRetry={reload} />

  const entries = data?.data ?? []

  return (
    <div className="flex flex-col gap-5">
      <Card
        title="لاگ فعالیت"
        subtitle={`${data?.meta.total ?? 0} رویداد ثبت‌شده — این فهرست فقط خواندنی است`}
        actions={
          <div className="w-56">
            <SelectField
              label=""
              options={data?.actions ?? []}
              placeholder="همه‌ی رویدادها"
              value={action}
              onChange={(e) => {
                setAction(e.target.value)
                setPage(1)
              }}
            />
          </div>
        }
      >
        {entries.length === 0 ? (
          <EmptyState message="رویدادی ثبت نشده است." />
        ) : (
          <ul className="flex flex-col gap-2">
            {entries.map((entry, index) => {
              const destructive = DESTRUCTIVE.some((k) => entry.action.includes(k))
              const open = expanded === entry.id
              const hasDetails = entry.properties && Object.keys(entry.properties).length > 0

              return (
                <motion.li
                  key={entry.id}
                  initial={{ opacity: 0, y: 8 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: Math.min(index * 0.02, 0.3) }}
                  className="rounded-xl border p-3"
                  style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-sunken)' }}
                >
                  <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                    <span
                      className="rounded-full px-2.5 py-0.5 text-[11px] font-semibold"
                      style={{
                        backgroundColor: `color-mix(in srgb, ${destructive ? 'var(--color-danger)' : 'var(--color-brand-500)'} 14%, transparent)`,
                        color: destructive ? 'var(--color-danger)' : 'var(--color-brand-600)',
                      }}
                    >
                      {entry.actionLabel}
                    </span>

                    <span className="text-[13px]" style={{ color: 'var(--text-primary)' }}>
                      {entry.description}
                    </span>

                    <span className="ms-auto text-[11px] tabular-nums" style={{ color: 'var(--text-tertiary)' }}>
                      {entry.at}
                    </span>
                  </div>

                  <div
                    className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px]"
                    style={{ color: 'var(--text-tertiary)' }}
                  >
                    <span>{entry.userName}</span>
                    {entry.userPhone && <span dir="ltr">{entry.userPhone}</span>}
                    {entry.ip && <span dir="ltr">IP: {entry.ip}</span>}

                    {hasDetails && (
                      <button
                        onClick={() => setExpanded(open ? null : entry.id)}
                        className="inline-flex items-center gap-1 underline"
                      >
                        {open ? <ChevronUp size={11} /> : <ChevronDown size={11} />}
                        جزئیات
                      </button>
                    )}
                  </div>

                  {open && hasDetails && (
                    <pre
                      className="mt-2 max-h-48 overflow-auto rounded-lg p-2.5 text-[11px] leading-5"
                      style={{ backgroundColor: 'var(--surface-base)', color: 'var(--text-secondary)' }}
                      dir="ltr"
                    >
                      {JSON.stringify(entry.properties, null, 2)}
                    </pre>
                  )}
                </motion.li>
              )
            })}
          </ul>
        )}

        {(data?.meta.lastPage ?? 1) > 1 && (
          <div className="mt-4 flex items-center justify-center gap-3">
            <button
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={page <= 1}
              className="rounded-lg border px-3 py-1.5 text-[12.5px] disabled:opacity-40"
              style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
            >
              جدیدتر
            </button>

            <span className="text-[12px] tabular-nums" style={{ color: 'var(--text-tertiary)' }}>
              {data?.meta.currentPage} از {data?.meta.lastPage}
            </span>

            <button
              onClick={() => setPage((p) => p + 1)}
              disabled={page >= (data?.meta.lastPage ?? 1)}
              className="rounded-lg border px-3 py-1.5 text-[12.5px] disabled:opacity-40"
              style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
            >
              قدیمی‌تر
            </button>
          </div>
        )}
      </Card>

      <p className="flex items-center gap-1.5 text-[11.5px]" style={{ color: 'var(--text-tertiary)' }}>
        <ScrollText size={13} />
        لاگ فعالیت با «بازیابی کل سیستم» پاک نمی‌شود، تا نشود ردِ کارها را شست.
      </p>
    </div>
  )
}
