import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Bell, Megaphone, Settings2 } from 'lucide-react'
import { api } from '@/shared/lib/api'
import { errorMessage } from '@/shared/lib/queryClient'
import { queryKeys } from '@/shared/lib/queryKeys'
import { useDocumentTitle } from '@/shared/hooks'
import { Card } from '@/shared/ui/Card'
import { EmptyState, ErrorState, InlineSpinner } from '@/shared/ui/PageState'
import { formatNumber } from '@/shared/lib/format'
import { cn } from '@/shared/lib/cn'
import { NotificationSettings } from './NotificationSettings'

interface HistoryItem {
  id: string
  kind: 'announcement' | 'personal'
  title: string
  excerpt: string
  isRead: boolean
  publishedAt: string | null
  link: string | null
}

interface HistoryResponse {
  items: HistoryItem[]
  total: number
  currentPage: number
  lastPage: number
  unreadCount: number
}

/**
 * تاریخچه‌ی اعلان‌ها و تنظیماتشان (R27).
 *
 * ─── چرا این صفحه لازم بود ─────────────────────────────────────────────────
 * دراپ‌داونِ زنگوله عمداً سه تا پنج آیتم نشان می‌دهد. تا امروز راهی برای
 * دیدنِ بقیه نبود: اعلانی که کاربر یک بار از دستش می‌داد — مثلاً نتیجه‌ی
 * بررسیِ رسیدِ پرداخت — برای همیشه از دسترس خارج می‌شد.
 */
export function NotificationsPage() {
  const [page, setPage] = useState(1)
  const [tab, setTab] = useState<'history' | 'settings'>('history')

  useDocumentTitle('اعلان‌ها')

  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.notifications.history(page),
    queryFn: ({ signal }) =>
      api<HistoryResponse>(`/notifications/history?page=${page}`, { signal }),
    enabled: tab === 'history',
  })

  return (
    <div className="flex flex-col gap-5">
      <header>
        <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
          اعلان‌ها
        </h1>
        <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
          تاریخچه‌ی کامل اطلاعیه‌ها و اعلان‌های شخصی شما
        </p>
      </header>

      <div className="flex items-center gap-2">
        {(
          [
            { value: 'history', label: 'تاریخچه', icon: Bell },
            { value: 'settings', label: 'تنظیمات', icon: Settings2 },
          ] as const
        ).map((option) => (
          <button
            key={option.value}
            type="button"
            onClick={() => setTab(option.value)}
            className={cn(
              'flex items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-[12.5px]',
              tab === option.value && 'font-bold',
            )}
            style={{
              borderColor: tab === option.value ? 'var(--color-brand-500)' : 'var(--border-subtle)',
              color: tab === option.value ? 'var(--color-brand-500)' : 'var(--text-secondary)',
            }}
          >
            <option.icon size={13} />
            {option.label}
          </button>
        ))}
      </div>

      {tab === 'settings' ? (
        <Card>
          <NotificationSettings />
        </Card>
      ) : isLoading ? (
        <InlineSpinner />
      ) : error ? (
        <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />
      ) : !data || data.items.length === 0 ? (
        <EmptyState
          message="هنوز اعلانی ندارید"
          hint="اطلاعیه‌ها و خبرهای شما اینجا جمع می‌شوند."
        />
      ) : (
        <>
          <ul className="flex flex-col gap-2">
            {data.items.map((item) => {
              const body = (
                <>
                  <div className="flex items-center gap-2">
                    {item.kind === 'announcement' ? (
                      <Megaphone size={14} style={{ color: 'var(--color-brand-500)' }} />
                    ) : (
                      <Bell size={14} style={{ color: 'var(--color-brand-500)' }} />
                    )}
                    <span
                      className={cn('text-[13.5px]', !item.isRead && 'font-bold')}
                      style={{ color: 'var(--text-primary)' }}
                    >
                      {item.title}
                    </span>
                    {/* نخوانده‌ها با نقطه مشخص می‌شوند، نه فقط با ضخامتِ قلم */}
                    {!item.isRead && (
                      <span
                        className="h-1.5 w-1.5 shrink-0 rounded-full"
                        style={{ backgroundColor: 'var(--color-brand-500)' }}
                      />
                    )}
                  </div>

                  {item.excerpt && (
                    <p
                      className="mt-1 text-[12.5px] leading-6"
                      style={{ color: 'var(--text-secondary)' }}
                    >
                      {item.excerpt}
                    </p>
                  )}

                  {item.publishedAt && (
                    <p className="mt-1 text-[11px]" style={{ color: 'var(--text-tertiary)' }}>
                      {item.publishedAt}
                    </p>
                  )}
                </>
              )

              return (
                <li
                  key={item.id}
                  className="rounded-2xl border p-4"
                  style={{
                    borderColor: 'var(--border-subtle)',
                    backgroundColor: 'var(--surface-base)',
                  }}
                >
                  {/* اعلانی که مقصد ندارد نباید شبیهِ لینک دیده شود */}
                  {item.link ? (
                    <Link to={item.link} className="block">
                      {body}
                    </Link>
                  ) : (
                    body
                  )}
                </li>
              )
            })}
          </ul>

          {data.lastPage > 1 && (
            <div className="flex items-center justify-center gap-3 text-[12.5px]">
              <button
                type="button"
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={data.currentPage <= 1}
                className="rounded-lg border px-3 py-1.5 disabled:opacity-40"
                style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
              >
                تازه‌تر
              </button>
              <span style={{ color: 'var(--text-tertiary)' }}>
                صفحه‌ی {formatNumber(data.currentPage)} از {formatNumber(data.lastPage)}
              </span>
              <button
                type="button"
                onClick={() => setPage((p) => Math.min(data.lastPage, p + 1))}
                disabled={data.currentPage >= data.lastPage}
                className="rounded-lg border px-3 py-1.5 disabled:opacity-40"
                style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
              >
                قدیمی‌تر
              </button>
            </div>
          )}
        </>
      )}
    </div>
  )
}
