import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { MessageSquareWarning } from 'lucide-react'
import { api } from '@/shared/lib/api'
import { alertError } from '@/shared/lib/alert'
import { errorMessage } from '@/shared/lib/queryClient'
import { queryKeys } from '@/shared/lib/queryKeys'
import { ErrorState, InlineSpinner } from '@/shared/ui/PageState'

interface Channel {
  value: string
  label: string
  description: string
  isSms: boolean
  enabled: boolean
}

/**
 * تنظیماتِ اعلانِ کاربر (R27).
 *
 * ─── چرا پیامک از بقیه جدا نشان داده می‌شود ────────────────────────────────
 * خاموش‌کردنِ زنگوله فقط سکوتِ درون‌برنامه است، ولی خاموش‌کردنِ پیامک یعنی
 * کاربر یادآوریِ بدهی را روی گوشی‌اش نمی‌گیرد. اینها تصمیم‌های هم‌وزن
 * نیستند و نباید کنارِ هم و شبیهِ هم دیده شوند.
 */
export function NotificationSettings() {
  const queryClient = useQueryClient()

  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.notifications.settings(),
    queryFn: ({ signal }) => api<{ channels: Channel[] }>('/notifications/settings', { signal }),
  })

  const toggle = useMutation({
    mutationFn: (input: { key: string; enabled: boolean }) =>
      api<{ channels: Channel[] }>('/notifications/settings', { method: 'PATCH', body: input }),
    onSuccess: (fresh) => queryClient.setQueryData(queryKeys.notifications.settings(), fresh),
    onError: (err) => alertError(err, 'ذخیره‌ی تنظیمات ممکن نشد.'),
  })

  if (isLoading) return <InlineSpinner />
  if (error) return <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />
  if (!data) return null

  return (
    <ul className="flex flex-col gap-2">
      {data.channels.map((channel) => (
        <li
          key={channel.value}
          className="flex items-start justify-between gap-3 rounded-xl px-3 py-2.5"
          style={{ backgroundColor: 'var(--surface-sunken)' }}
        >
          <div>
            <p
              className="flex items-center gap-1.5 text-[13px] font-bold"
              style={{ color: 'var(--text-primary)' }}
            >
              {channel.isSms && (
                <MessageSquareWarning size={13} style={{ color: 'var(--color-brand-500)' }} />
              )}
              {channel.label}
            </p>
            <p className="mt-0.5 text-[11.5px]" style={{ color: 'var(--text-tertiary)' }}>
              {channel.description}
            </p>
          </div>

          <label className="mt-1 shrink-0">
            <input
              type="checkbox"
              checked={channel.enabled}
              disabled={toggle.isPending}
              onChange={(event) =>
                toggle.mutate({ key: channel.value, enabled: event.target.checked })
              }
              aria-label={channel.label}
            />
          </label>
        </li>
      ))}
    </ul>
  )
}
