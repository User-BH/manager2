import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowRight, Flag, Hash, Lock, MessageSquare, Plus, Users } from 'lucide-react'
import { ErrorState, InlineSpinner } from '@/shared/ui/PageState'
import { useDocumentTitle } from '@/shared/hooks'
import { api } from '@/shared/lib/api'
import { cn } from '@/shared/lib/cn'
import { queryKeys } from '@/shared/lib/queryKeys'
import { ConversationThread } from './ConversationThread'
import { NewConversationDialog } from './NewConversationDialog'
import { ReportsPanel } from './ReportsPanel'
import { messageActions } from './messageActions'
import type { ConversationSummary, ConversationsResponse, ConversationType } from './types'

const ICONS: Record<ConversationType, typeof Hash> = {
  channel: Hash,
  unit: Lock,
  user: Lock,
  group: Users,
}

/**
 * پیام‌رسانِ گفت‌وگومحور (مرحلهٔ ۶ · فاز ۱۲).
 *
 * فهرستِ گفت‌وگوها کنارِ گفت‌وگوی باز؛ روی موبایل یکی از دو، با آدرس
 * (`/messenger/:id`) تا «بازگشت»ِ مرورگر به فهرست برگردد.
 *
 * ساکن از همان بارِ اول کانالِ مجتمع و رشته‌ی خصوصیِ خودش با مدیریت را دارد؛
 * مدیر رشته‌ی هر واحد را وقتی پیامی دارد می‌بیند و می‌تواند رشته یا گروهِ
 * تازه باز کند.
 */
export function MessengerPage() {
  useDocumentTitle('پیام‌رسان')

  const { id } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [creating, setCreating] = useState(false)
  const [reviewing, setReviewing] = useState(false)

  const selectedId = id && /^\d+$/.test(id) ? Number(id) : null

  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.messenger.conversations(),
    queryFn: ({ signal }) => api<ConversationsResponse>('/messenger/conversations', { signal }),
    // شمارِ نخوانده‌ی گفت‌وگوهای دیگر؛ فقط وقتی تب دیده می‌شود
    refetchInterval: 15_000,
    refetchIntervalInBackground: false,
  })

  // شمارِ گزارش‌های باز — فقط مدیر؛ همان کلیدِ پنلِ گزارش‌ها
  const { data: reports } = useQuery({
    queryKey: queryKeys.messenger.reports(),
    queryFn: ({ signal }) => messageActions.reports(signal),
    enabled: Boolean(data?.isAdmin),
    refetchInterval: 60_000,
    refetchIntervalInBackground: false,
  })

  if (isLoading) return <InlineSpinner />
  if (error) return <ErrorState error={error} onRetry={() => void refetch()} />

  const conversations = data?.data ?? []

  function open(conversation: ConversationSummary) {
    void navigate(`/messenger/${conversation.id}`)
  }

  return (
    <div className="flex h-[calc(100vh-8rem)] flex-col gap-4">
      <header className="flex items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
            پیام‌رسان
          </h1>
          <p className="mt-1 text-body" style={{ color: 'var(--text-tertiary)' }}>
            گفت‌وگوی داخلی ساکنین و مدیریت مجتمع
          </p>
        </div>
        <div className="flex items-center gap-2">
          {data?.isAdmin && (
            <button
              type="button"
              onClick={() => setReviewing(true)}
              className="flex items-center gap-1.5 rounded-xl border px-3 py-2 text-small font-semibold"
              style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
            >
              <Flag size={15} /> گزارش‌ها
              {reports && reports.openCount > 0 && (
                <span
                  className="rounded-full px-1.5 text-tiny font-bold tabular-nums text-white"
                  style={{ backgroundColor: 'var(--color-danger)' }}
                  aria-label={`${reports.openCount} گزارشِ باز`}
                >
                  {reports.openCount}
                </span>
              )}
            </button>
          )}
          {data?.isAdmin && data.canSend && (
            <button
              type="button"
              onClick={() => setCreating(true)}
              className="flex items-center gap-1.5 rounded-xl px-3 py-2 text-small font-semibold text-white"
              style={{ backgroundColor: 'var(--color-brand-500)' }}
            >
              <Plus size={16} /> گفت‌وگوی تازه
            </button>
          )}
        </div>
      </header>

      {data?.reason && (
        <p className="text-small" style={{ color: 'var(--text-secondary)' }}>
          {data.reason}
        </p>
      )}

      <div className="grid min-h-0 flex-1 grid-cols-1 gap-4 md:grid-cols-[18rem_minmax(0,1fr)]">
        <nav
          aria-label="گفت‌وگوها"
          className={cn(
            'scrollbar-thin min-h-0 overflow-y-auto rounded-2xl border p-2',
            selectedId !== null && 'hidden md:block',
          )}
          style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
        >
          {conversations.length === 0 ? (
            <p className="p-4 text-center text-small" style={{ color: 'var(--text-tertiary)' }}>
              گفت‌وگویی نیست.
            </p>
          ) : (
            <ul className="flex flex-col gap-1">
              {conversations.map((conversation) => {
                const Icon = ICONS[conversation.type]
                const active = conversation.id === selectedId

                return (
                  <li key={conversation.id}>
                    <button
                      type="button"
                      onClick={() => open(conversation)}
                      aria-current={active ? 'true' : undefined}
                      className="flex w-full items-start gap-2.5 rounded-xl px-3 py-2.5 text-right"
                      style={{ backgroundColor: active ? 'var(--tone-brand-bg)' : 'transparent' }}
                    >
                      <Icon
                        size={16}
                        className="mt-0.5 shrink-0"
                        style={{ color: 'var(--text-tertiary)' }}
                      />
                      <span className="min-w-0 flex-1">
                        <span className="flex items-center justify-between gap-2">
                          <span
                            className="truncate text-small font-semibold"
                            style={{ color: 'var(--text-primary)' }}
                          >
                            {conversation.title}
                          </span>
                          {conversation.unreadCount > 0 && (
                            <span
                              className="rounded-full px-1.5 text-tiny font-bold tabular-nums text-white"
                              style={{ backgroundColor: 'var(--color-brand-500)' }}
                              aria-label={`${conversation.unreadCount} پیامِ نخوانده`}
                            >
                              {conversation.unreadCount}
                            </span>
                          )}
                        </span>
                        <span
                          className="block truncate text-tiny"
                          style={{ color: 'var(--text-tertiary)' }}
                        >
                          {conversation.lastMessage
                            ? `${conversation.lastMessage.isMine ? 'شما' : conversation.lastMessage.authorName}: ${conversation.lastMessage.preview ?? 'پیامِ پنهان‌شده'}`
                            : 'هنوز پیامی نیست'}
                        </span>
                      </span>
                    </button>
                  </li>
                )
              })}
            </ul>
          )}
        </nav>

        <section className={cn('min-h-0', selectedId === null && 'hidden md:block')}>
          {selectedId !== null ? (
            <div className="flex h-full min-h-0 flex-col gap-2">
              <button
                type="button"
                onClick={() => void navigate('/messenger')}
                className="flex items-center gap-1 self-start text-small md:hidden"
                style={{ color: 'var(--text-secondary)' }}
              >
                <ArrowRight size={16} /> همه‌ی گفت‌وگوها
              </button>
              <div className="min-h-0 flex-1">
                <ConversationThread key={selectedId} conversationId={selectedId} />
              </div>
            </div>
          ) : (
            <div
              className="flex h-full flex-col items-center justify-center gap-2 rounded-2xl border text-center"
              style={{ borderColor: 'var(--border-subtle)' }}
            >
              <MessageSquare size={30} style={{ color: 'var(--text-tertiary)' }} />
              <p className="text-small" style={{ color: 'var(--text-secondary)' }}>
                یک گفت‌وگو را انتخاب کنید.
              </p>
            </div>
          )}
        </section>
      </div>

      {reviewing && (
        <ReportsPanel
          onClose={() => setReviewing(false)}
          onOpenConversation={(conversationId) => {
            setReviewing(false)
            void navigate(`/messenger/${conversationId}`)
          }}
        />
      )}

      {creating && data && (
        <NewConversationDialog
          units={data.units}
          onClose={() => setCreating(false)}
          onCreated={(conversation) => {
            setCreating(false)
            void queryClient.invalidateQueries({ queryKey: queryKeys.messenger.all() })
            open(conversation)
          }}
        />
      )}
    </div>
  )
}
