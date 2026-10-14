import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Modal } from '@/shared/ui/Modal'
import { ErrorState, InlineSpinner } from '@/shared/ui/PageState'
import { api } from '@/shared/lib/api'
import { alertError, toastSuccess } from '@/shared/lib/alert'
import { queryKeys } from '@/shared/lib/queryKeys'
import { messageActions } from './messageActions'
import type { ChatMessage, ConversationsResponse } from './types'

/**
 * هدایتِ یک پیام (مرحلهٔ ۱۰ · فاز ۱۰؛ بندهای ۵۲ و ۵۳).
 *
 * مقصدها فقط گفت‌وگوهایی‌اند که سرور می‌گوید این شخص در آن‌ها **می‌نویسد** (`canPost`). سرور به‌هرحال هر دو دسترسی را
 * جدا می‌سنجد: پیامِ مبدأ باید برایش دیدنی و مقصد نوشتنی باشد. نسخه‌ای جدا در مقصد ساخته می‌شود، با نامِ نویسنده‌ی
 * مبدأ؛ تکرارِ همان کلیک پیامِ دوم نمی‌سازد (`client_id`).
 */
export function ForwardDialog({
  message,
  fromConversationId,
  onClose,
}: {
  message: ChatMessage
  fromConversationId: number
  onClose: () => void
}) {
  const [clientId] = useState(() => `fw-${message.id}-${Date.now()}`)
  const [sending, setSending] = useState<number | null>(null)

  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.messenger.conversations('', 1),
    queryFn: ({ signal }) => api<ConversationsResponse>('/messenger/conversations?', { signal }),
  })

  const targets = (data?.data ?? []).filter((c) => c.canPost && c.id !== fromConversationId)

  async function send(targetId: number) {
    setSending(targetId)
    try {
      await messageActions.forward(targetId, message.id, clientId)
      toastSuccess('پیام هدایت شد.')
      onClose()
    } catch (failure) {
      alertError(failure, 'هدایت انجام نشد.')
    } finally {
      setSending(null)
    }
  }

  return (
    <Modal open title="هدایت به…" onClose={onClose}>
      {isLoading && <InlineSpinner />}
      {error && <ErrorState error={error} onRetry={() => void refetch()} />}
      {data && (
        <ul aria-label="مقصدِ هدایت" className="flex flex-col gap-1">
          {targets.length === 0 && (
            <li className="p-3 text-center text-small" style={{ color: 'var(--text-tertiary)' }}>
              گفت‌وگویی برای هدایت نیست.
            </li>
          )}
          {targets.map((target) => (
            <li key={target.id}>
              <button
                type="button"
                disabled={sending !== null}
                onClick={() => void send(target.id)}
                className="w-full rounded-xl px-3 py-2 text-right text-small hover:bg-[var(--surface-sunken)] disabled:opacity-60"
                style={{ color: 'var(--text-primary)' }}
              >
                {target.title}
              </button>
            </li>
          ))}
        </ul>
      )}
    </Modal>
  )
}
