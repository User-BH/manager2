import { useState } from 'react'
import { motion } from 'framer-motion'
import { ChevronDown, ChevronUp, ScrollText } from 'lucide-react'
import { Card } from '@/shared/ui/Card'
import { SelectField } from '@/shared/ui/Field'
import { EmptyState, ErrorState } from '@/shared/ui/PageState'
import { TableSkeleton } from '@/shared/ui/Skeleton'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/shared/lib/api'
import { queryKeys } from '@/shared/lib/queryKeys'
import { useCursorList, useDocumentTitle } from '@/shared/hooks'

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

/**
 * پاسخِ نشانگری (R30).
 *
 * `meta.total` عمداً حذف شد: شمردنِ کلِ ردیف‌های یک جدولِ افزایشی در هر
 * درخواست گران است و برای جوابِ «دکمه‌ی ادامه را نشان بدهم؟» لازم نیست.
 */
interface AuditResponse {
  data: AuditEntry[]
  hasMore: boolean
  nextCursor: number | null
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
  const [expanded, setExpanded] = useState<number | null>(null)

  /*
   * صفحه‌بندیِ نشانگری (R30).
   *
   * با شماره‌ی صفحه، رویدادهای تازه‌ای که بین دو درخواست ثبت می‌شوند فهرست
   * را جابه‌جا می‌کردند و ادمین در صفحه‌ی ۲ همان چیزی را می‌دید که در
   * صفحه‌ی ۱ دیده بود — و به همان تعداد، رویدادِ قدیمی‌تر را هرگز نمی‌دید.
   * روی لاگِ امنیتی این یعنی موردی که باید بررسی می‌شد از دست برود.
   */
  const { items, hasMore, isLoading, isLoadingMore, error, loadMore } = useCursorList<AuditEntry>(
    '/system/audit-logs',
    action ? { action } : {},
  )

  const { data: meta } = useQuery({
    queryKey: queryKeys.system.auditLogs(),
    // فقط برای فهرستِ فیلترها؛ خودِ ردیف‌ها از قلابِ نشانگری می‌آیند
    queryFn: ({ signal }) => api<AuditResponse>('/system/audit-logs', { signal }),
  })

  if (isLoading) return <TableSkeleton rows={8} columns={4} />
  if (error) return <ErrorState message={error} onRetry={() => window.location.reload()} />

  const entries = items

  return (
    <div className="flex flex-col gap-5">
      <Card
        title="لاگ فعالیت"
        subtitle="تازه‌ترین رویدادها اول — این فهرست فقط خواندنی است"
        actions={
          <div className="w-56">
            <SelectField
              label=""
              options={meta?.actions ?? []}
              placeholder="همه‌ی رویدادها"
              value={action}
              // با تغییرِ فیلتر، قلابِ نشانگری خودش از ابتدا می‌خواند
              onChange={(e) => setAction(e.target.value)}
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
                  style={{
                    borderColor: 'var(--border-subtle)',
                    backgroundColor: 'var(--surface-sunken)',
                  }}
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

                    <span
                      className="ms-auto text-[11px] tabular-nums"
                      style={{ color: 'var(--text-tertiary)' }}
                    >
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
                      style={{
                        backgroundColor: 'var(--surface-base)',
                        color: 'var(--text-secondary)',
                      }}
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

        {/* «ادامه» به‌جای شماره‌ی صفحه: فهرست فقط رو به گذشته باز می‌شود */}
        {hasMore && (
          <div className="mt-4 flex justify-center">
            <button
              onClick={() => void loadMore()}
              disabled={isLoadingMore}
              className="rounded-lg border px-4 py-2 text-[12.5px] disabled:opacity-50"
              style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
            >
              {isLoadingMore ? 'در حال دریافت…' : 'رویدادهای قدیمی‌تر'}
            </button>
          </div>
        )}
      </Card>

      <p
        className="flex items-center gap-1.5 text-[11.5px]"
        style={{ color: 'var(--text-tertiary)' }}
      >
        <ScrollText size={13} />
        لاگ فعالیت با «بازیابی کل سیستم» پاک نمی‌شود، تا نشود ردِ کارها را شست.
      </p>
    </div>
  )
}
