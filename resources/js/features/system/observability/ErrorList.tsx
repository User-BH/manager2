import { useState } from 'react'
import { useCursorList } from '@/shared/hooks'
import { Check, ChevronDown, ChevronUp, Globe, Server } from 'lucide-react'

import { Card } from '@/shared/ui/Card'
import { EmptyState, ErrorState } from '@/shared/ui/PageState'
import { TableSkeleton } from '@/shared/ui/Skeleton'
import { useApiAction } from '@/shared/hooks/useAction'
import { queryKeys } from '@/shared/lib/queryKeys'
import { formatNumber } from '@/shared/lib/format'

/**
 * خطاهای ثبت‌شده در دیتابیسِ خودمان.
 *
 * این فهرست **مستقل از Sentry** کار می‌کند. اگر روزی Sentry وصل شد، آنجا ابزارِ
 * تحلیلِ حرفه‌ای‌تری هست؛ ولی این صفحه از روزِ اول داده‌ی واقعی دارد و به هیچ
 * حسابِ بیرونی وابسته نیست.
 *
 * رخدادهای هم‌شکل با «اثرِ انگشت» گروه می‌شوند، پس یک باگِ پرتکرار **یک ردیف**
 * با شمارنده‌ی بالاست، نه هزار ردیفِ تکراری.
 */

interface ErrorRow {
  id: number
  source: 'server' | 'client'
  sourceLabel: string
  type: string
  fullType: string
  message: string
  file: string | null
  line: number | null
  stack: string | null
  url: string | null
  method: string | null
  status: number | null
  occurrences: number
  userName: string | null
  firstSeen: string | null
  lastSeen: string | null
  isResolved: boolean
}

export function ErrorList() {
  const [includeResolved, setIncludeResolved] = useState(false)
  const [expanded, setExpanded] = useState<number | null>(null)

  /*
   * صفحه‌بندیِ نشانگری (R30).
   *
   * پیش از این این فهرست **هیچ** صفحه‌بندی‌ای در رابط نداشت: سرور ۲۰ ردیفِ
   * اول را می‌داد و بقیه اصلاً قابلِ دیدن نبودند. حالا «ادامه» هست، و
   * نشانگر به‌جای شماره‌ی صفحه چون جدولِ خطاها افزایشی است.
   */
  const { items, hasMore, isLoading, isLoadingMore, error, loadMore, reload } =
    useCursorList<ErrorRow>('/system/observability/errors', {
      include_resolved: includeResolved ? '1' : '0',
    })

  const { call, isPending } = useApiAction()

  function resolve(row: ErrorRow) {
    void call(
      `/system/observability/errors/${row.id}/resolve`,
      { method: 'PATCH' },
      {
        key: row.id,
        success: 'خطا بررسی‌شده علامت خورد.',
        errorFallback: 'ثبت وضعیت ممکن نشد.',
        invalidate: [queryKeys.system.all()],
        // فهرستِ نشانگری کش ندارد، پس خودش باید دوباره خوانده شود
        onDone: reload,
      },
    )
  }

  return (
    <Card
      title="خطاهای ثبت‌شده"
      subtitle="رخدادهای هم‌شکل یک ردیف‌اند؛ عدد یعنی چند بار تکرار شده"
      delay={0.15}
      actions={
        <label
          className="flex items-center gap-1.5 text-[12px]"
          style={{ color: 'var(--text-secondary)' }}
        >
          <input
            type="checkbox"
            className="h-4 w-4 rounded"
            checked={includeResolved}
            onChange={(e) => setIncludeResolved(e.target.checked)}
          />
          نمایش بررسی‌شده‌ها
        </label>
      }
    >
      {isLoading && <TableSkeleton rows={5} columns={4} />}
      {error && <ErrorState message={error} onRetry={reload} />}

      {!isLoading && items.length === 0 && (
        <EmptyState
          message="هیچ خطایی ثبت نشده است."
          hint="این خبرِ خوبی است — یعنی از آخرین بررسی، کرشی رخ نداده."
        />
      )}

      {items.length > 0 && (
        <ul className="flex flex-col gap-2">
          {items.map((row) => {
            const open = expanded === row.id

            return (
              <li
                key={row.id}
                className="rounded-xl border p-3"
                style={{
                  borderColor: 'var(--border-subtle)',
                  backgroundColor: 'var(--surface-sunken)',
                  opacity: row.isResolved ? 0.6 : 1,
                }}
              >
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                  <span
                    className="flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px] font-semibold"
                    style={{
                      backgroundColor: `color-mix(in srgb, ${
                        row.source === 'server' ? 'var(--color-danger)' : 'var(--color-accent-500)'
                      } 14%, transparent)`,
                      color:
                        row.source === 'server' ? 'var(--color-danger)' : 'var(--color-accent-600)',
                    }}
                  >
                    {row.source === 'server' ? <Server size={11} /> : <Globe size={11} />}
                    {row.sourceLabel}
                  </span>

                  <span
                    className="font-mono text-[12px] font-bold"
                    style={{ color: 'var(--text-primary)' }}
                    dir="ltr"
                  >
                    {row.type}
                  </span>

                  {row.occurrences > 1 && (
                    <span
                      className="rounded-full px-2 py-0.5 text-[11px] font-bold tabular-nums"
                      style={{
                        backgroundColor: 'color-mix(in srgb, var(--color-danger) 12%, transparent)',
                        color: 'var(--color-danger)',
                      }}
                    >
                      ×{formatNumber(row.occurrences)}
                    </span>
                  )}

                  <span className="ms-auto text-[11px]" style={{ color: 'var(--text-tertiary)' }}>
                    {row.lastSeen}
                  </span>
                </div>

                <p
                  className="mt-1.5 break-words text-[12.5px]"
                  style={{ color: 'var(--text-secondary)' }}
                  dir="ltr"
                >
                  {row.message}
                </p>

                <div
                  className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px]"
                  style={{ color: 'var(--text-tertiary)' }}
                >
                  {row.url && (
                    <span className="max-w-full truncate" dir="ltr">
                      {row.url}
                    </span>
                  )}
                  {row.userName && <span>{row.userName}</span>}

                  {(row.stack || row.file) && (
                    <button
                      onClick={() => setExpanded(open ? null : row.id)}
                      className="inline-flex items-center gap-1 underline"
                    >
                      {open ? <ChevronUp size={11} /> : <ChevronDown size={11} />}
                      جزئیات فنی
                    </button>
                  )}

                  {!row.isResolved && (
                    <button
                      onClick={() => resolve(row)}
                      disabled={isPending(row.id)}
                      className="inline-flex items-center gap-1 underline disabled:opacity-50"
                      style={{ color: 'var(--state-success)' }}
                    >
                      <Check size={11} />
                      بررسی شد
                    </button>
                  )}
                </div>

                {open && (
                  <pre
                    className="mt-2 max-h-60 overflow-auto rounded-lg p-2.5 text-[11px] leading-5"
                    style={{
                      backgroundColor: 'var(--surface-base)',
                      color: 'var(--text-secondary)',
                    }}
                    dir="ltr"
                  >
                    {row.file ? `${row.file}:${row.line ?? '?'}\n\n` : ''}
                    {row.stack ?? '(بدون استک)'}
                  </pre>
                )}
              </li>
            )
          })}
        </ul>
      )}
      {/* «ادامه» — پیش از این هیچ راهی برای دیدنِ خطاهای بعد از ۲۰ تای اول نبود */}
      {hasMore && (
        <div className="mt-4 flex justify-center">
          <button
            type="button"
            onClick={() => void loadMore()}
            disabled={isLoadingMore}
            className="rounded-lg border px-4 py-2 text-[12.5px] disabled:opacity-50"
            style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
          >
            {isLoadingMore ? 'در حال دریافت…' : 'خطاهای قدیمی‌تر'}
          </button>
        </div>
      )}
    </Card>
  )
}
