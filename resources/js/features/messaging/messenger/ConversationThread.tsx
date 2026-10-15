import { useCallback, useEffect, useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Loader2, Lock, Pin, Search } from 'lucide-react'
import { ErrorState, InlineSpinner } from '@/shared/ui/PageState'
import { api, ApiError } from '@/shared/lib/api'
import { alertError, confirmAction, promptText, toastSuccess } from '@/shared/lib/alert'
import { cn } from '@/shared/lib/cn'
import { queryKeys } from '@/shared/lib/queryKeys'
import { useAuth } from '@/shared/stores/authStore'
import { Composer, type ComposeTarget, type OutgoingDraft } from './Composer'
import { MessageBubble, type BubbleHandlers } from './MessageBubble'
import { messageActions } from './messageActions'
import { ThreadSearch } from './ThreadSearch'
import { GroupInfoPanel } from './GroupInfoPanel'
import { ForwardDialog } from './ForwardDialog'
import { OnlineCount, OnlineLabel, clockOffset } from './presence'
import { copyText } from '@/shared/lib/clipboard'
import { formatNumber } from '@/shared/lib/format'
import {
  applyReadUpTo,
  markFailed,
  markPending,
  mergeMessages,
  newClientId,
  newestId,
  removeByClientId,
} from './threadState'
import type { ChatMessage, ConversationSummary, MessagesResponse, UploadRules } from './types'

const POLL_INTERVAL = 8000

/** هم‌پوشانیِ `changed_after`: ساعتِ سرور ثانیه‌ای است و تغییرِ همان ثانیه نباید جا بماند. */
const CHANGE_OVERLAP_SECONDS = 2

interface ThreadInfo {
  conversation: ConversationSummary | null
  canSend: boolean
  isAdmin: boolean
  /** ۱۰/۱۰: چند سنجاق، تازه‌ترین اول */
  pins: ChatMessage[]
  /** حضور («چند نکته»، بندِ ۳): شمارِ آنلاینِ گروه، ساعتِ سرور و اختلافش با این دستگاه */
  onlineCount: number | null
  serverTime: number | undefined
  clockOffsetMs: number
}

/**
 * یک گفت‌وگو (مرحلهٔ ۶ · فاز ۱۲ و ۱۴).
 *
 * ─── بی‌درنگ نیست (تصمیمِ ۲) ────────────────────────────────────────────────
 * پیامِ تازه با پرسشِ دوره‌ای (`since`) می‌رسد — فقط وقتی تب دیده می‌شود. همان
 * پرسش ویرایش، حذف، واکنش و پنهان‌شدنِ پیام‌های از قبل رسیده را هم می‌آورد
 * (`changed_after`، فاز ۱۴).
 *
 * ─── تاریخچه ─────────────────────────────────────────────────────────────
 * صفحه‌ی آخر اول می‌آید و قدیمی‌تر با نشانگر، بی‌آنکه جای اسکرول بپرد. کلِ
 * تاریخچه هرگز یک‌جا بار نمی‌شود، و ردیف‌های بیرون از قاب با
 * `content-visibility: auto` رندر نمی‌شوند (`.chat-row` در CSS).
 *
 * ─── تحویل ───────────────────────────────────────────────────────────────
 * ارسال خوش‌بینانه است و `client_id` دارد: پیامِ ناموفق با متنش می‌ماند و
 * «دوباره» همان شناسه را می‌فرستد — اگر اولی در واقع رسیده بود، سرور همان را
 * برمی‌گرداند و پیامِ دوم ساخته نمی‌شود.
 */
export function ConversationThread({ conversationId }: { conversationId: number }) {
  const { user } = useAuth()
  const queryClient = useQueryClient()

  const [showInfo, setShowInfo] = useState(false)
  const [forwarding, setForwarding] = useState<ChatMessage | null>(null)
  const [pinIndex, setPinIndex] = useState(0)
  const [uploads, setUploads] = useState<UploadRules | null>(null)
  const [messages, setMessages] = useState<ChatMessage[]>([])
  const [info, setInfo] = useState<ThreadInfo>({
    conversation: null,
    canSend: false,
    isAdmin: false,
    pins: [],
    onlineCount: null,
    serverTime: undefined,
    clockOffsetMs: 0,
  })
  const [older, setOlder] = useState<{ has: boolean; cursor: number | null; loading: boolean }>({
    has: false,
    cursor: null,
    loading: false,
  })
  const [load, setLoad] = useState<{ loading: boolean; error: unknown }>({
    loading: true,
    error: null,
  })
  const [notice, setNotice] = useState<string | null>(null)
  const [compose, setCompose] = useState<ComposeTarget>(null)
  // یک منوی باز در هر لحظه، و جعبه‌ی جست‌وجو
  const [ui, setUi] = useState<{ menu: number | null; search: boolean }>({
    menu: null,
    search: false,
  })

  const listRef = useRef<HTMLDivElement>(null)
  const outgoingRef = useRef(new Map<string, OutgoingDraft>())
  const readUpToRef = useRef(0)
  const serverTimeRef = useRef(0)
  // آخرین فهرست برای تایمر، بی‌آنکه هر پیام تایمر را از نو بسازد
  const messagesRef = useRef<ChatMessage[]>([])
  useEffect(() => {
    messagesRef.current = messages
  }, [messages])

  const scrollToBottom = useCallback((force = false) => {
    const el = listRef.current
    if (!el) return
    const nearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 120
    if (force || nearBottom) requestAnimationFrame(() => (el.scrollTop = el.scrollHeight))
  }, [])

  const apply = useCallback((data: MessagesResponse) => {
    if (data.serverTime) serverTimeRef.current = data.serverTime
    setInfo({
      conversation: data.conversation,
      canSend: data.canSend,
      isAdmin: data.isAdmin,
      pins: data.pins ?? (data.pinned ? [data.pinned] : []),
      onlineCount: data.onlineCount ?? null,
      serverTime: data.serverTime,
      clockOffsetMs: clockOffset(data.serverTime),
    })
    if (data.uploads) setUploads(data.uploads)
    setMessages((current) =>
      applyReadUpTo(
        mergeMessages(
          current,
          [...(data.changed ?? []), ...data.messages],
          data.hiddenIds,
          data.isAdmin,
        ),
        data.readUpTo,
      ),
    )
  }, [])

  const replace = useCallback(
    (updated: ChatMessage) => setMessages((current) => mergeMessages(current, [updated])),
    [],
  )

  // بارِ اول: تازه‌ترین صفحه
  useEffect(() => {
    let cancelled = false

    api<MessagesResponse>(`/messenger/conversations/${conversationId}/messages`)
      .then((data) => {
        if (cancelled) return
        apply(data)
        setOlder({ has: data.hasOlder, cursor: data.nextCursor, loading: false })
        setLoad({ loading: false, error: null })
        scrollToBottom(true)
      })
      .catch((error: unknown) => !cancelled && setLoad({ loading: false, error }))

    return () => {
      cancelled = true
    }
  }, [conversationId, apply, scrollToBottom])

  // پیام‌های تازه و تغییرها، فقط وقتی تب دیده می‌شود
  useEffect(() => {
    if (load.loading) return

    const poll = () => {
      const changedAfter = Math.max(0, serverTimeRef.current - CHANGE_OVERLAP_SECONDS)

      void api<MessagesResponse>(
        `/messenger/conversations/${conversationId}/messages?since=${newestId(messagesRef.current)}&changed_after=${changedAfter}`,
      )
        .then((data) => {
          apply(data)
          if (data.messages.length > 0) scrollToBottom()
        })
        .catch(() => {
          // خطای گذرا؛ تیکِ بعدی دوباره می‌پرسد
        })
    }

    let timer: ReturnType<typeof setInterval> | null = null
    const start = () => {
      if (timer === null) timer = setInterval(poll, POLL_INTERVAL)
    }
    const stop = () => {
      if (timer !== null) clearInterval(timer)
      timer = null
    }
    const onVisibility = () => {
      if (document.hidden) {
        stop()
      } else {
        poll()
        start()
      }
    }

    if (!document.hidden) start()
    document.addEventListener('visibilitychange', onVisibility)

    return () => {
      stop()
      document.removeEventListener('visibilitychange', onVisibility)
    }
  }, [conversationId, load.loading, apply, scrollToBottom])

  /*
   * رسیدِ خواندن: «تا این پیام خواندم» — یک درخواست برای کلِ گفت‌وگو، نه یکی
   * برای هر پیام. شکستش بی‌صداست؛ تیکِ بعد دوباره می‌فرستد.
   */
  useEffect(() => {
    if (document.hidden) return
    const upTo = messages.reduce((max, m) => (!m.isMine && m.id > max ? m.id : max), 0)
    if (upTo <= readUpToRef.current) return

    const timer = setTimeout(() => {
      readUpToRef.current = upTo
      void api(`/messenger/conversations/${conversationId}/read`, {
        method: 'POST',
        body: { up_to: upTo },
      })
        .then(() => queryClient.invalidateQueries({ queryKey: queryKeys.messenger.all() }))
        .catch(() => {
          readUpToRef.current = 0
        })
    }, 800)

    return () => clearTimeout(timer)
  }, [messages, conversationId, queryClient])

  async function loadOlder() {
    if (!older.cursor || older.loading) return
    const el = listRef.current
    const before = el?.scrollHeight ?? 0

    setOlder((o) => ({ ...o, loading: true }))
    try {
      const data = await api<MessagesResponse>(
        `/messenger/conversations/${conversationId}/messages?before=${older.cursor}`,
      )
      apply(data)
      setOlder({ has: data.hasOlder, cursor: data.nextCursor, loading: false })
      // جای خواندن نمی‌پرد: همان فاصله از پایین حفظ می‌شود
      requestAnimationFrame(() => {
        if (el) el.scrollTop += el.scrollHeight - before
      })
    } catch (error) {
      setOlder((o) => ({ ...o, loading: false }))
      alertError(error, 'پیام‌های قدیمی‌تر بار نشد.')
    }
  }

  async function deliver(clientId: string) {
    const outgoing = outgoingRef.current.get(clientId)
    if (!outgoing) return

    const payload: Record<string, string | string[]> = { client_id: clientId, body: outgoing.body }
    if (outgoing.replyTo) payload.reply_to_id = String(outgoing.replyTo.id)

    if (outgoing.poll && info.isAdmin) {
      const poll = outgoing.poll
      payload.poll_question = poll.question.trim()
      const options = poll.options.map((o) => o.text.trim()).filter(Boolean)
      payload.poll_options = options
      payload.poll_voter_scope = poll.voterScope
      payload.poll_weight_mode = poll.weightMode
      payload.poll_allow_change = poll.allowChange ? '1' : '0'
      // چندگزینه‌ای (۱۰/۱۱)؛ سقف از شمارِ گزینه‌ها نمی‌گذرد — سرور هم می‌سنجد
      payload.poll_max_selections = String(
        Math.min(Number(poll.maxSelections) || 1, options.length),
      )
      if (poll.quorumPercent) payload.poll_quorum_percent = poll.quorumPercent
      if (poll.closesAt) payload.poll_closes_at = new Date(poll.closesAt).toISOString()
    }

    let body: unknown = payload
    if (outgoing.attachment) {
      const form = new FormData()
      // پیامِ صوتی فیلدِ خودش را دارد؛ سرور نوعش را از همین می‌فهمد، نه از حدس
      form.append(
        outgoing.voice ? 'voice' : outgoing.videoMessage ? 'video_message' : 'attachment',
        outgoing.attachment,
      )
      for (const [key, value] of Object.entries(payload)) {
        if (Array.isArray(value)) value.forEach((item) => form.append(`${key}[]`, item))
        else form.append(key, value)
      }
      body = form
    }

    setMessages((current) => markPending(current, clientId))

    try {
      const { message } = await api<{ message: ChatMessage }>(
        `/messenger/conversations/${conversationId}/messages`,
        { method: 'POST', body },
      )
      outgoingRef.current.delete(clientId)
      replace(message)
      void queryClient.invalidateQueries({ queryKey: queryKeys.messenger.all() })
    } catch (error) {
      setMessages((current) => markFailed(current, clientId))
      setNotice(error instanceof ApiError ? error.message : 'ارسالِ پیام ناموفق بود.')
    }
  }

  async function send(draft: OutgoingDraft) {
    const clientId = newClientId()
    outgoingRef.current.set(clientId, draft)
    setCompose(null)

    setMessages((current) => [
      ...current,
      {
        id: -Date.now(),
        clientId,
        body:
          draft.body ||
          (draft.voice
            ? 'پیامِ صوتی'
            : draft.videoMessage
              ? 'پیامِ ویدیویی'
              : (draft.attachment?.name ?? '')),
        authorName: user?.name ?? 'شما',
        unitLabel: '',
        isMine: true,
        isHidden: false,
        sentAt: '',
        status: 'pending',
        replyTo: draft.replyTo
          ? {
              id: draft.replyTo.id,
              authorName: draft.replyTo.authorName,
              excerpt: draft.replyTo.body,
              isDeleted: false,
            }
          : null,
      },
    ])
    scrollToBottom(true)

    await deliver(clientId)
  }

  async function edit(message: ChatMessage, body: string) {
    const { message: updated } = await messageActions.edit(conversationId, message.id, body)
    replace(updated)
    setCompose(null)
  }

  function discard(clientId: string) {
    outgoingRef.current.delete(clientId)
    setMessages((current) => removeByClientId(current, clientId))
  }

  /** پرش به پیامی که در فهرستِ بارشده هست؛ وگرنه همین را می‌گوید، نه سکوت. */
  function jumpTo(id: number) {
    const row = listRef.current?.querySelector<HTMLElement>(`[data-message-id="${id}"]`)
    if (!row) {
      setNotice('این پیام در بخشِ بارشده نیست؛ «پیام‌های قدیمی‌تر» را بزنید.')
      return
    }
    row.scrollIntoView({ block: 'center' })
    row.classList.add('chat-row-flash')
    setTimeout(() => row.classList.remove('chat-row-flash'), 1600)
  }

  function handlersFor(message: ChatMessage): BubbleHandlers {
    const act = (task: () => Promise<{ message: ChatMessage }>, failure: string) => () =>
      void task()
        .then(({ message: updated }) => replace(updated))
        .catch((error: unknown) => alertError(error, failure))

    const isPinned = info.pins.some((pin) => pin.id === message.id)

    return {
      onReply: () => setCompose({ mode: 'reply', message }),
      onEdit: () => setCompose({ mode: 'edit', message }),
      onReact: (emoji) =>
        act(() => messageActions.react(conversationId, message.id, emoji), 'واکنش ثبت نشد.')(),
      onDelete: () =>
        void confirmAction({
          title: 'حذفِ پیام',
          text: 'متن و پیوستِ این پیام برای همه پاک می‌شود و برنمی‌گردد.',
          confirmLabel: 'حذف',
          danger: true,
        }).then((ok) => {
          if (ok) act(() => messageActions.remove(conversationId, message.id), 'حذف انجام نشد.')()
        }),
      onPin: () =>
        void messageActions
          .pin(conversationId, message.id, !isPinned)
          .then(({ pins }) => setInfo((current) => ({ ...current, pins })))
          .catch((error: unknown) => alertError(error, 'سنجاق ثبت نشد.')),
      onCopy: () =>
        void copyText(message.body ?? '').then((ok) =>
          ok ? toastSuccess('متن کپی شد.') : alertError(null, 'کپی ممکن نشد.'),
        ),
      onForward: () => setForwarding(message),
      onDownload: () => {
        if (!message.attachment) return
        const link = document.createElement('a')
        link.href = message.attachment.url
        link.download = message.attachment.name
        link.click()
      },
      onReport: () =>
        void promptText({
          title: 'گزارش به مدیریت',
          text: 'مدیریت پیام را می‌بیند، نه نامِ شما را.',
          placeholder: 'دلیل (اختیاری)',
          confirmLabel: 'گزارش',
        }).then((reason) => {
          if (reason === null) return
          messageActions
            .report(conversationId, message.id, reason.trim() || null)
            .then(({ message: text }) => toastSuccess(text))
            .catch((error: unknown) => alertError(error, 'گزارش ثبت نشد.'))
        }),
      onHide: () =>
        void api<{ message: ChatMessage }>(`/messenger/${message.id}/toggle-hide`, {
          method: 'PATCH',
        })
          .then(({ message: updated }) => {
            replace(updated)
            toastSuccess(
              updated.isHidden ? 'پیام برای ساکنین پنهان شد.' : 'پیام دوباره نمایش داده می‌شود.',
            )
          })
          .catch((error: unknown) => alertError(error, 'تغییر وضعیت پیام ممکن نشد.')),
      onRetry: () => message.clientId && void deliver(message.clientId),
      onDiscard: () => message.clientId && discard(message.clientId),
      onJump: jumpTo,
      onPollChange: (poll) => replace({ ...message, poll }),
    }
  }

  if (load.loading) return <InlineSpinner />
  if (load.error) return <ErrorState error={load.error} />

  const { conversation, pins } = info
  const pinned = pins.length > 0 ? pins[pinIndex % pins.length] : null
  const readOnlyGroup = conversation && !conversation.canPost

  return (
    <div className="flex h-full min-h-0 flex-col gap-3">
      <header className="flex items-center justify-between gap-2">
        {/* بندِ ۳۵: کلیک روی نامِ گروه اطلاعاتِ گروه را باز می‌کند */}
        {conversation?.type === 'channel' || conversation?.type === 'group' ? (
          <div className="flex min-w-0 flex-col">
            <button
              type="button"
              onClick={() => setShowInfo(true)}
              className="text-right text-body font-bold"
              style={{ color: 'var(--text-primary)' }}
              aria-label={`اطلاعاتِ ${conversation.title}`}
            >
              {conversation.title}
            </button>
            <OnlineCount
              count={info.onlineCount}
              serverTime={info.serverTime}
              offsetMs={info.clockOffsetMs}
            />
          </div>
        ) : (
          <div className="flex min-w-0 flex-col">
            <h2 className="text-body font-bold" style={{ color: 'var(--text-primary)' }}>
              {conversation?.title}
            </h2>
            <OnlineLabel until={conversation?.peerOnlineUntil} offsetMs={info.clockOffsetMs} />
          </div>
        )}
        <div className="flex items-center gap-3">
          {conversation && ['unit', 'user', 'pair', 'saved'].includes(conversation.type) ? (
            <span
              className="flex items-center gap-1 text-tiny"
              style={{ color: 'var(--text-tertiary)' }}
            >
              <Lock size={12} /> خصوصی
            </span>
          ) : null}
          <button
            type="button"
            onClick={() => setUi((current) => ({ ...current, search: !current.search }))}
            aria-label="جست‌وجو در این گفت‌وگو"
            aria-pressed={ui.search}
            style={{ color: 'var(--text-tertiary)' }}
          >
            <Search size={16} />
          </button>
        </div>
      </header>

      {ui.search && (
        <ThreadSearch
          conversationId={conversationId}
          onPick={jumpTo}
          onClose={() => setUi((current) => ({ ...current, search: false }))}
        />
      )}

      {pinned && (
        <button
          type="button"
          onClick={() => {
            jumpTo(pinned.id)
            // سنجاق‌ها یکی‌یکی، مثلِ پیام‌رسان‌های آشنا
            setPinIndex((index) => index + 1)
          }}
          className="flex items-center gap-2 rounded-xl border px-3 py-2 text-right text-small"
          style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
        >
          <Pin size={14} style={{ color: 'var(--color-brand-500)' }} aria-hidden="true" />
          <span className="sr-only">پیامِ سنجاق‌شده:</span>
          <span className="min-w-0 flex-1 truncate" style={{ color: 'var(--text-primary)' }}>
            {pinned.body || pinned.attachment?.name || 'نظرسنجی'}
          </span>
          {pins.length > 1 && (
            <span className="text-tiny tabular-nums" style={{ color: 'var(--text-tertiary)' }}>
              {formatNumber((pinIndex % pins.length) + 1)} از {formatNumber(pins.length)}
            </span>
          )}
        </button>
      )}

      <div
        ref={listRef}
        className="scrollbar-thin min-h-0 flex-1 overflow-y-auto rounded-2xl border p-4"
        style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
      >
        {older.has && (
          <div className="mb-3 flex justify-center">
            <button
              type="button"
              onClick={() => void loadOlder()}
              disabled={older.loading}
              className="flex items-center gap-1 rounded-full border px-3 py-1 text-tiny"
              style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
            >
              {older.loading && <Loader2 size={12} className="animate-spin" />}
              پیام‌های قدیمی‌تر
            </button>
          </div>
        )}

        {messages.length === 0 ? (
          <p className="py-10 text-center text-small" style={{ color: 'var(--text-tertiary)' }}>
            هنوز پیامی رد و بدل نشده است.
          </p>
        ) : (
          <ul className="flex flex-col gap-3" aria-label="پیام‌ها">
            {messages.map((message) => (
              <li
                key={message.clientId && message.id < 0 ? message.clientId : message.id}
                data-message-id={message.id > 0 ? message.id : undefined}
                className={cn(
                  'chat-row flex',
                  message.isMine ? 'justify-start' : 'justify-end',
                  ui.menu === message.id && 'chat-row-active',
                )}
              >
                <MessageBubble
                  message={message}
                  isAdmin={info.isAdmin}
                  isPinned={pins.some((pin) => pin.id === message.id)}
                  menuOpen={ui.menu === message.id}
                  onToggleMenu={(open) =>
                    setUi((current) => ({ ...current, menu: open ? message.id : null }))
                  }
                  handlers={handlersFor(message)}
                />
              </li>
            ))}
          </ul>
        )}
      </div>

      {notice && (
        <p role="alert" className="text-small" style={{ color: 'var(--color-danger)' }}>
          {notice}
        </p>
      )}

      {info.canSend ? (
        <Composer
          // نظرسنجی فقط در گروه (بندِ ۶۱) — دکمه‌اش هم فقط همان‌جا
          isAdmin={info.isAdmin}
          uploads={uploads ?? undefined}
          canPoll={
            info.isAdmin &&
            (info.conversation?.type === 'channel' || info.conversation?.type === 'group')
          }
          target={compose}
          onCancelTarget={() => setCompose(null)}
          onSend={send}
          onEdit={edit}
          onNotice={setNotice}
        />
      ) : (
        <div
          className="flex items-center gap-2 rounded-xl px-4 py-3 text-small"
          style={{ backgroundColor: 'var(--surface-sunken)', color: 'var(--text-secondary)' }}
        >
          <Lock size={15} />
          {readOnlyGroup
            ? (conversation.readOnlyReason ?? 'در این گفت‌وگو فقط مدیریت پیام می‌فرستد.')
            : 'امکان ارسال پیام برای شما فعال نیست.'}
        </div>
      )}

      {showInfo && (
        <GroupInfoPanel conversationId={conversationId} onClose={() => setShowInfo(false)} />
      )}

      {forwarding && (
        <ForwardDialog
          message={forwarding}
          fromConversationId={conversationId}
          onClose={() => setForwarding(null)}
        />
      )}
    </div>
  )
}
