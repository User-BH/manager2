import { useQuery, useQueryClient } from '@tanstack/react-query'
import { EyeOff, MessageSquare, X } from 'lucide-react'
import { Modal } from '@/shared/ui/Modal'
import { ErrorState, InlineSpinner } from '@/shared/ui/PageState'
import { alertError, toastSuccess } from '@/shared/lib/alert'
import { queryKeys } from '@/shared/lib/queryKeys'
import { messageActions } from './messageActions'

/**
 * صفِ گزارش‌های پیام برای مدیر (مرحلهٔ ۶ · فاز ۱۴).
 *
 * هر پیام یک سطر با شمارِ گزارش‌ها — نامِ گزارش‌دهنده به مدیر نمی‌رسد.
 * «پنهان کردن» همان ابزارِ برگشت‌پذیرِ همیشگیِ مدیر است؛ «کنار گذاشتن»
 * فقط گزارش را می‌بندد.
 */
export function ReportsPanel({
  onClose,
  onOpenConversation,
}: {
  onClose: () => void
  onOpenConversation: (id: number) => void
}) {
  const queryClient = useQueryClient()
  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.messenger.reports(),
    queryFn: ({ signal }) => messageActions.reports(signal),
  })

  async function resolve(id: number, resolution: 'hidden' | 'dismissed') {
    try {
      const { message } = await messageActions.resolve(id, resolution)
      toastSuccess(message)
      await queryClient.invalidateQueries({ queryKey: queryKeys.messenger.all() })
    } catch (failure) {
      alertError(failure, 'رسیدگی ثبت نشد.')
    }
  }

  return (
    <Modal open title="گزارش‌های پیام" onClose={onClose}>
      {isLoading ? (
        <InlineSpinner />
      ) : error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : !data || data.data.length === 0 ? (
        <p className="py-6 text-center text-small" style={{ color: 'var(--text-tertiary)' }}>
          گزارشِ بازی نیست.
        </p>
      ) : (
        <ul className="flex flex-col gap-3">
          {data.data.map((report) => (
            <li
              key={report.id}
              className="rounded-xl border p-3"
              style={{ borderColor: 'var(--border-subtle)' }}
            >
              <p className="text-tiny" style={{ color: 'var(--text-tertiary)' }}>
                {report.authorName} · {report.reportedAt} · {report.reportCount} گزارش
                {report.isHidden && ' · پنهان‌شده'}
              </p>
              <p
                className="mt-1 whitespace-pre-line text-small"
                style={{ color: 'var(--text-primary)' }}
              >
                {report.excerpt || '—'}
              </p>
              {report.reasons.length > 0 && (
                <ul
                  className="mt-1 list-inside list-disc text-tiny"
                  style={{ color: 'var(--text-secondary)' }}
                >
                  {report.reasons.map((reason, index) => (
                    <li key={index}>{reason}</li>
                  ))}
                </ul>
              )}
              <div className="mt-2 flex flex-wrap gap-2 text-small">
                {!report.isHidden && (
                  <button
                    type="button"
                    onClick={() => void resolve(report.id, 'hidden')}
                    className="flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-white"
                    style={{ backgroundColor: 'var(--color-danger)' }}
                  >
                    <EyeOff size={13} /> پنهان کردن
                  </button>
                )}
                <button
                  type="button"
                  onClick={() => void resolve(report.id, 'dismissed')}
                  className="flex items-center gap-1 rounded-lg border px-2.5 py-1.5"
                  style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
                >
                  <X size={13} /> کنار گذاشتن
                </button>
                {report.conversationId !== null && (
                  <button
                    type="button"
                    onClick={() =>
                      report.conversationId && onOpenConversation(report.conversationId)
                    }
                    className="flex items-center gap-1 rounded-lg border px-2.5 py-1.5"
                    style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
                  >
                    <MessageSquare size={13} /> دیدنِ گفت‌وگو
                  </button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
    </Modal>
  )
}
