import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Laptop, LogOut, ShieldAlert, ShieldCheck } from 'lucide-react'
import { Card } from '@/shared/ui/Card'
import { ErrorState, LoadingState } from '@/shared/ui/PageState'
import { api } from '@/shared/lib/api'
import { alertError, confirmAction, toastSuccess } from '@/shared/lib/alert'
import { formatJalaliDateTime, formatRelative } from '@/shared/lib/format'
import { queryKeys } from '@/shared/lib/queryKeys'
import type { LoginEventRow, SecurityOverview } from './types'

/**
 * نشست‌ها، دستگاه‌های مورداعتماد و تاریخچه‌ی ورود (مرحلهٔ ۵ · فاز ۱۰).
 *
 * کاربری که هشدارِ «ورود از دستگاهِ تازه» گرفته، همین‌جا می‌بیند چه کسی و
 * از کجا وارد است و با یک دکمه همه را بیرون می‌کند. بستنِ نشست و لغوِ
 * دستگاه رمز می‌خواهند؛ پنجره‌اش را `api()` خودش باز می‌کند.
 */
export function SecurityCard() {
  const queryClient = useQueryClient()
  const [busy, setBusy] = useState(false)

  const sessions = useQuery({
    queryKey: queryKeys.profile.sessions(),
    queryFn: ({ signal }) => api<SecurityOverview>('/profile/sessions', { signal }),
  })

  const history = useQuery({
    queryKey: queryKeys.profile.loginHistory(),
    queryFn: ({ signal }) => api<{ events: LoginEventRow[] }>('/profile/login-history', { signal }),
  })

  async function run(action: () => Promise<{ message: string }>) {
    setBusy(true)
    try {
      const { message } = await action()
      toastSuccess(message)
      await queryClient.invalidateQueries({ queryKey: queryKeys.profile.sessions() })
    } catch (error) {
      alertError(error, 'انجام نشد.')
    } finally {
      setBusy(false)
    }
  }

  async function endOthers() {
    const confirmed = await confirmAction({
      title: 'خروج از همه‌ی دستگاه‌های دیگر؟',
      text: 'همه‌ی نشست‌های دیگر بسته و اعتمادِ دستگاه‌های دیگر لغو می‌شود. همین دستگاه وارد می‌ماند.',
      confirmLabel: 'خارج کن',
      danger: true,
    })

    if (confirmed) {
      await run(() => api('/profile/sessions/end-others', { method: 'POST' }))
    }
  }

  const others = (sessions.data?.sessions ?? []).filter((session) => !session.current).length
  const otherDevices = (sessions.data?.devices ?? []).filter((device) => !device.current).length

  return (
    <Card
      title="نشست‌ها و دستگاه‌ها"
      subtitle="اگر دستگاهی را نمی‌شناسید، از همه‌جا خارج شوید و رمز عبور را عوض کنید."
      delay={0.15}
    >
      {sessions.isLoading ? (
        <LoadingState rows={2} />
      ) : sessions.error ? (
        <ErrorState error={sessions.error} onRetry={() => void sessions.refetch()} />
      ) : sessions.data && !sessions.data.supported ? (
        <p className="text-small" style={{ color: 'var(--text-tertiary)' }}>
          مدیریتِ نشست روی این سرور فعال نیست.
        </p>
      ) : (
        <div className="flex flex-col gap-4">
          <ul className="flex flex-col gap-2" aria-label="نشست‌های باز">
            {sessions.data?.sessions.map((session) => (
              <li
                key={session.id}
                className="flex items-center gap-3 rounded-xl border p-3"
                style={{ borderColor: 'var(--border-subtle)' }}
              >
                <Laptop size={18} style={{ color: 'var(--text-tertiary)' }} />
                <div className="min-w-0 flex-1">
                  <p className="text-small font-semibold" style={{ color: 'var(--text-primary)' }}>
                    {session.device}
                    {session.current && (
                      <span className="mr-2 text-tiny" style={{ color: 'var(--state-success)' }}>
                        · همین دستگاه
                      </span>
                    )}
                  </p>
                  <p className="text-tiny tabular-nums" style={{ color: 'var(--text-tertiary)' }}>
                    {session.ip && <span dir="ltr">{session.ip}</span>}
                    {session.ip && ' · '}
                    آخرین فعالیت {formatRelative(new Date(session.lastActiveAt))}
                  </p>
                </div>
                {!session.current && (
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() =>
                      void run(() => api(`/profile/sessions/${session.id}`, { method: 'DELETE' }))
                    }
                    className="rounded-lg border px-2.5 py-1 text-small disabled:opacity-50"
                    style={{ borderColor: 'var(--border-subtle)', color: 'var(--color-danger)' }}
                  >
                    خروج
                  </button>
                )}
              </li>
            ))}
          </ul>

          {(sessions.data?.devices.length ?? 0) > 0 && (
            <div>
              <p
                className="mb-2 text-small font-semibold"
                style={{ color: 'var(--text-secondary)' }}
              >
                دستگاه‌های مورداعتماد («مرا به خاطر بسپار»)
              </p>
              <ul className="flex flex-col gap-2" aria-label="دستگاه‌های مورداعتماد">
                {sessions.data?.devices.map((device) => (
                  <li
                    key={device.id}
                    className="flex items-center justify-between gap-3 text-small"
                    style={{ color: 'var(--text-secondary)' }}
                  >
                    <span>
                      {device.device}
                      {device.current && ' · همین دستگاه'}
                    </span>
                    <button
                      type="button"
                      disabled={busy}
                      onClick={() =>
                        void run(() => api(`/profile/devices/${device.id}`, { method: 'DELETE' }))
                      }
                      className="rounded-lg border px-2.5 py-1 disabled:opacity-50"
                      style={{ borderColor: 'var(--border-subtle)' }}
                    >
                      لغوِ اعتماد
                    </button>
                  </li>
                ))}
              </ul>
            </div>
          )}

          <button
            type="button"
            disabled={busy || (others === 0 && otherDevices === 0)}
            onClick={() => void endOthers()}
            className="flex items-center justify-center gap-2 rounded-xl border px-4 py-2 text-small font-semibold disabled:opacity-50"
            style={{ borderColor: 'var(--color-danger)', color: 'var(--color-danger)' }}
          >
            <LogOut size={16} />
            خروج از همه‌ی دستگاه‌های دیگر
          </button>
        </div>
      )}

      <LoginHistory
        loading={history.isLoading}
        error={history.error}
        events={history.data?.events ?? []}
      />
    </Card>
  )
}

const EVENT_LABELS: Record<string, string> = {
  success: 'ورود',
  trusted_device: 'ورودِ خودکار (دستگاهِ مورداعتماد)',
  password_reset: 'ورود پس از بازیابیِ رمز',
  failed_password: 'رمزِ نادرست',
  failed_otp: 'کدِ پیامکیِ نادرست',
  failed_reauth: 'رمزِ نادرست در تأییدِ دوباره',
}

function LoginHistory({
  loading,
  error,
  events,
}: {
  loading: boolean
  error: unknown
  events: LoginEventRow[]
}) {
  return (
    <section className="mt-5 border-t pt-4" style={{ borderColor: 'var(--border-subtle)' }}>
      <h3 className="mb-2 text-small font-semibold" style={{ color: 'var(--text-secondary)' }}>
        تاریخچه‌ی ورود
      </h3>

      {loading ? (
        <LoadingState rows={2} />
      ) : error ? (
        <ErrorState error={error} />
      ) : events.length === 0 ? (
        <p className="text-small" style={{ color: 'var(--text-tertiary)' }}>
          هنوز ثبتی نیست.
        </p>
      ) : (
        <ul className="flex max-h-72 flex-col gap-1.5 overflow-y-auto" aria-label="تاریخچه‌ی ورود">
          {events.map((event) => (
            <li key={event.id} className="flex items-start gap-2 text-small">
              {event.failed ? (
                <ShieldAlert
                  size={14}
                  className="mt-0.5 shrink-0"
                  style={{ color: 'var(--color-danger)' }}
                />
              ) : (
                <ShieldCheck
                  size={14}
                  className="mt-0.5 shrink-0"
                  style={{ color: 'var(--state-success)' }}
                />
              )}
              <span style={{ color: 'var(--text-secondary)' }}>
                <strong
                  style={{ color: event.failed ? 'var(--color-danger)' : 'var(--text-primary)' }}
                >
                  {EVENT_LABELS[event.event] ?? event.event}
                </strong>
                {' · '}
                {event.device}
                {event.ip && (
                  <>
                    {' · '}
                    <span dir="ltr">{event.ip}</span>
                  </>
                )}
                {' · '}
                <span className="tabular-nums">{formatJalaliDateTime(new Date(event.at))}</span>
              </span>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
