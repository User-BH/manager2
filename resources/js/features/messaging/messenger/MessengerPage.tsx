import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowRight, Flag, Hash, Lock, MessageSquare, Plus, Users } from 'lucide-react'
import { ErrorState, InlineSpinner } from '@/shared/ui/PageState'
import { useDebounce, useDocumentTitle } from '@/shared/hooks'
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
  const [term, setTerm] = useState('')
  const [pages, setPages] = useState(1)

  const selectedId = id && /^\d+$/.test(id) ? Number(id) : null

  // کمتر از دو نویسه یعنی فهرستِ کامل — همان قاعده‌ی سرور
  const typed = useDebounce(term.trim(), 350)
  const search = typed.length >= 2 ? typed : ''

  /*
   * فهرست صفحه به صفحه با نشانگرِ خودِ سرور (مرحلهٔ ۱۰ · فاز ۸؛ بندِ ۴۱). ⚠️ تا پیش از این فقط ۳۰ گفت‌وگوی اول
   * خوانده می‌شد و `nextCursor` بی‌مصرف می‌ماند: مدیری با صدها رشته به سی‌ویکمی هرگز نمی‌رسید. جست‌وجو همان
   * فهرست است با `q`؛ سرور پس از قاعده‌ی دید باریکش می‌کند.
   *
   * «بیشتر» شمارِ صفحه را بالا می‌برد و یک پرسش همه را پشتِ هم با نشانگرها می‌خواند — پس پرسشِ دوره‌ای شمارِ
   * نخوانده‌ی صفحه‌های قبلی را هم تازه می‌کند و گفت‌وگویی که به بالا پریده دو بار دیده نمی‌شود. ⚠️ عمداً نه
   * `useInfiniteQuery`: همان یک هوک چانکِ مشترکی را که صفحه‌ی پشتیبانی هم بار می‌کند از سقفش رد می‌کرد
   * (`bundleBudget`).
   */
  const { data, error, isLoading, isFetching, refetch } = useQuery({
    queryKey: queryKeys.messenger.conversations(search, pages),
    queryFn: async ({ signal }) => {
      const read: ConversationsResponse[] = []
      let cursor: string | null = null

      do {
        const params = new URLSearchParams()
        if (search) params.set('q', search)
        if (cursor) params.set('cursor', cursor)
        const page: ConversationsResponse = await api<ConversationsResponse>(
          `/messenger/conversations?${params.toString()}`,
          { signal },
        )
        read.push(page)
        cursor = page.nextCursor
      } while (cursor && read.length < pages)

      return { ...read[0], data: read.flatMap((page) => page.data), nextCursor: cursor }
    },
    // نوشتن در کادرِ جست‌وجو یا «بیشتر» فهرست را به چرخنده تبدیل نمی‌کند
    placeholderData: keepPreviousData,
    // شمارِ نخوانده‌ی گفت‌وگوهای دیگر؛ فقط وقتی تب دیده می‌شود
    refetchInterval: 15_000,
    refetchIntervalInBackground: false,
  })
  const first = data

  // شمارِ گزارش‌های باز — فقط مدیر؛ همان کلیدِ پنلِ گزارش‌ها
  const { data: reports } = useQuery({
    queryKey: queryKeys.messenger.reports(),
    queryFn: ({ signal }) => messageActions.reports(signal),
    enabled: Boolean(first?.isAdmin),
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
          {first?.isAdmin && (
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
          {first?.isAdmin && first.canSend && (
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

      {first?.reason && (
        <p className="text-small" style={{ color: 'var(--text-secondary)' }}>
          {first.reason}
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
          {/* بندِ ۴۱: مدیر با صدها گفت‌وگو؛ برای ساکن (بندِ ۴۲) الزامی نیست و گفت‌وگوهایش انگشت‌شمارند */}
          {first?.isAdmin && (
            <input
              type="search"
              value={term}
              onChange={(event) => {
                setTerm(event.target.value)
                setPages(1)
              }}
              placeholder="مالک واحد ۱۰، نامِ ساکن، واحد ۲۵…"
              aria-label="جست‌وجوی گفت‌وگوها"
              className="field mb-2 w-full rounded-xl px-3 py-2 text-small"
            />
          )}
          {conversations.length === 0 ? (
            <p className="p-4 text-center text-small" style={{ color: 'var(--text-tertiary)' }}>
              {search ? `گفت‌وگویی با «${search}» پیدا نشد.` : 'گفت‌وگویی نیست.'}
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
          {data?.nextCursor && (
            <button
              type="button"
              disabled={isFetching}
              onClick={() => setPages((count) => count + 1)}
              className="mt-2 w-full rounded-xl py-2 text-small font-semibold disabled:opacity-60"
              style={{ color: 'var(--color-brand-600)', backgroundColor: 'var(--surface-sunken)' }}
            >
              {isFetching ? 'در حال بارگذاری…' : 'گفت‌وگوهای بیشتر'}
            </button>
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

      {creating && first && (
        <NewConversationDialog
          units={first.units}
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
