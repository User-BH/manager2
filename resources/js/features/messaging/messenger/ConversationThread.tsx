import { useCallback, useEffect, useRef, useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { useQueryClient } from '@tanstack/react-query'
import {
  AlertCircle,
  BarChart3,
  Check,
  CheckCheck,
  Clock,
  Eye,
  EyeOff,
  FileText,
  Loader2,
  Lock,
  Paperclip,
  RotateCcw,
  Send,
  X,
} from 'lucide-react'
import { z } from 'zod'
import { ErrorState, InlineSpinner } from '@/shared/ui/PageState'
import { PollCard } from '@/shared/ui/PollCard'
import { api, ApiError } from '@/shared/lib/api'
import { alertError, toastSuccess } from '@/shared/lib/alert'
import { cn } from '@/shared/lib/cn'
import { queryKeys } from '@/shared/lib/queryKeys'
import { useAuth } from '@/shared/stores/authStore'
import { EmojiPicker } from './EmojiPicker'
import { PollComposer, emptyPoll, type PollDraft } from './PollComposer'
import {
  applyReadUpTo,
  markFailed,
  markPending,
  mergeMessages,
  newClientId,
  newestId,
  removeByClientId,
} from './threadState'
import type { ChatMessage, ConversationSummary, DeliveryStatus, MessagesResponse } from './types'

/** همان قیدِ سرور؛ اینجا فقط تا کاربر پیش از آپلود بفهمد. */
const MAX_ATTACHMENT_BYTES = 4 * 1024 * 1024
const ACCEPTED_TYPES = '.jpg,.jpeg,.png,.webp,.pdf'
const POLL_INTERVAL = 8000

const messageSchema = z.object({ body: z.string().max(1000, 'پیام بیش از حد طولانی است') })
type MessageFormValues = z.infer<typeof messageSchema>

/** همان داده‌ای که فرستاده شد، تا «دوباره» عیناً همان را — با همان شناسه — بفرستد. */
interface Outgoing {
  body: string
  attachment: File | null
  poll: PollDraft | null
}

/**
 * یک گفت‌وگو (مرحلهٔ ۶ · فاز ۱۲).
 *
 * ─── بی‌درنگ نیست (تصمیمِ ۲) ────────────────────────────────────────────────
 * پیامِ تازه با پرسشِ دوره‌ای (`since`) می‌رسد — فقط وقتی تب دیده می‌شود.
 *
 * ─── تاریخچه ─────────────────────────────────────────────────────────────
 * صفحه‌ی آخر اول می‌آید و قدیمی‌تر با نشانگر، بی‌آنکه جای اسکرول بپرد. کلِ
 * تاریخچه هرگز یک‌جا بار نمی‌شود، و ردیف‌های بیرون از قاب با
 * `content-visibility: auto` رندر نمی‌شوند (`.chat-row` در CSS) — مجازی‌سازیِ
 * بی‌کتابخانه برای ردیف‌های هم‌ارتفاع‌نبودنِ گفت‌وگو.
 *
 * ─── تحویل ───────────────────────────────────────────────────────────────
 * ارسال خوش‌بینانه است و `client_id` دارد: پیامِ ناموفق با متنش می‌ماند و
 * «دوباره» همان شناسه را می‌فرستد — اگر اولی در واقع رسیده بود، سرور همان را
 * برمی‌گرداند و پیامِ دوم ساخته نمی‌شود.
 */
export function ConversationThread({ conversationId }: { conversationId: number }) {
  const { user } = useAuth()
  const queryClient = useQueryClient()

  const [messages, setMessages] = useState<ChatMessage[]>([])
  const [conversation, setConversation] = useState<ConversationSummary | null>(null)
  const [meta, setMeta] = useState({ canSend: false, isAdmin: false })
  const [older, setOlder] = useState<{ has: boolean; cursor: number | null; loading: boolean }>({
    has: false,
    cursor: null,
    loading: false,
  })
  const [isLoading, setIsLoading] = useState(true)
  const [loadError, setLoadError] = useState<unknown>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [attachment, setAttachment] = useState<File | null>(null)
  const [pollDraft, setPollDraft] = useState<PollDraft | null>(null)

  const listRef = useRef<HTMLDivElement>(null)
  const fileInputRef = useRef<HTMLInputElement>(null)
  const textareaRef = useRef<HTMLTextAreaElement | null>(null)
  const outgoingRef = useRef(new Map<string, Outgoing>())
  const readUpToRef = useRef(0)
  // آخرین فهرست برای تایمر، بی‌آنکه هر پیام تایمر را از نو بسازد
  const messagesRef = useRef<ChatMessage[]>([])
  useEffect(() => {
    messagesRef.current = messages
  }, [messages])

  const {
    register,
    handleSubmit,
    reset,
    setValue,
    setError: setFormError,
    formState: { errors, isSubmitting },
  } = useForm<MessageFormValues>({
    resolver: zodResolver(messageSchema),
    defaultValues: { body: '' },
  })

  const scrollToBottom = useCallback((force = false) => {
    const el = listRef.current
    if (!el) return
    const nearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 120
    if (force || nearBottom) requestAnimationFrame(() => (el.scrollTop = el.scrollHeight))
  }, [])

  const apply = useCallback((data: MessagesResponse) => {
    setConversation(data.conversation)
    setMeta({ canSend: data.canSend, isAdmin: data.isAdmin })
    setMessages((current) =>
      applyReadUpTo(
        mergeMessages(current, data.messages, data.hiddenIds, data.isAdmin),
        data.readUpTo,
      ),
    )
  }, [])

  // بارِ اول: تازه‌ترین صفحه
  useEffect(() => {
    let cancelled = false

    api<MessagesResponse>(`/messenger/conversations/${conversationId}/messages`)
      .then((data) => {
        if (cancelled) return
        apply(data)
        setOlder({ has: data.hasOlder, cursor: data.nextCursor, loading: false })
        setLoadError(null)
        scrollToBottom(true)
      })
      .catch((error: unknown) => !cancelled && setLoadError(error))
      .finally(() => !cancelled && setIsLoading(false))

    return () => {
      cancelled = true
    }
  }, [conversationId, apply, scrollToBottom])

  // پیام‌های تازه، فقط وقتی تب دیده می‌شود
  useEffect(() => {
    if (isLoading) return

    const poll = () =>
      void api<MessagesResponse>(
        `/messenger/conversations/${conversationId}/messages?since=${newestId(messagesRef.current)}`,
      )
        .then((data) => {
          apply(data)
          if (data.messages.length > 0) scrollToBottom()
        })
        .catch(() => {
          // خطای گذرا؛ تیکِ بعدی دوباره می‌پرسد
        })

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
  }, [conversationId, isLoading, apply, scrollToBottom])

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

  function insertEmoji(emoji: string) {
    const field = textareaRef.current
    if (!field) return
    const start = field.selectionStart ?? field.value.length
    const end = field.selectionEnd ?? start
    setValue('body', field.value.slice(0, start) + emoji + field.value.slice(end), {
      shouldValidate: true,
    })
    requestAnimationFrame(() => {
      field.focus()
      field.setSelectionRange(start + emoji.length, start + emoji.length)
    })
  }

  function pickAttachment(file: File | null) {
    if (file && file.size > MAX_ATTACHMENT_BYTES) {
      setNotice('حجم فایل باید کمتر از ۴ مگابایت باشد.')
      return
    }
    setNotice(null)
    setAttachment(file)
  }

  async function deliver(clientId: string) {
    const outgoing = outgoingRef.current.get(clientId)
    if (!outgoing) return

    const payload: Record<string, string | string[]> = { client_id: clientId, body: outgoing.body }

    if (outgoing.poll && meta.isAdmin) {
      const poll = outgoing.poll
      payload.poll_question = poll.question.trim()
      payload.poll_options = poll.options.map((o) => o.text.trim()).filter(Boolean)
      payload.poll_voter_scope = poll.voterScope
      payload.poll_weight_mode = poll.weightMode
      payload.poll_allow_change = poll.allowChange ? '1' : '0'
      if (poll.quorumPercent) payload.poll_quorum_percent = poll.quorumPercent
      if (poll.closesAt) payload.poll_closes_at = new Date(poll.closesAt).toISOString()
    }

    let body: unknown = payload
    if (outgoing.attachment) {
      const form = new FormData()
      form.append('attachment', outgoing.attachment)
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
      setMessages((current) => mergeMessages(current, [message]))
      void queryClient.invalidateQueries({ queryKey: queryKeys.messenger.all() })
    } catch (error) {
      setMessages((current) => markFailed(current, clientId))
      setNotice(error instanceof ApiError ? error.message : 'ارسالِ پیام ناموفق بود.')
    }
  }

  function submitForm(event: React.FormEvent<HTMLFormElement>) {
    void handleSubmit(onSubmit)(event)
  }

  /*
   * ⚠️ منتظرِ تحویل می‌ماند تا دکمه در این فاصله خاموش باشد: دوبار-کلیک همان
   * متن را با دو شناسه‌ی جدا دو بار می‌فرستاد (قراردادِ فرم‌ها).
   */
  async function onSubmit(values: MessageFormValues) {
    const hasPoll = Boolean(pollDraft?.question.trim())

    if (!values.body.trim() && !attachment && !hasPoll) {
      setFormError('body', { message: 'متن پیام را وارد کنید' })
      return
    }
    if (hasPoll && pollDraft!.options.filter((o) => o.text.trim()).length < 2) {
      setNotice('نظرسنجی باید دست‌کم دو گزینه داشته باشد.')
      return
    }

    const clientId = newClientId()
    outgoingRef.current.set(clientId, {
      body: values.body,
      attachment,
      poll: hasPoll ? pollDraft : null,
    })

    setMessages((current) => [
      ...current,
      {
        id: -Date.now(),
        clientId,
        body: values.body || (attachment ? attachment.name : ''),
        authorName: user?.name ?? 'شما',
        unitLabel: '',
        isMine: true,
        isHidden: false,
        sentAt: '',
        status: 'pending',
      },
    ])

    setNotice(null)
    reset()
    setAttachment(null)
    setPollDraft(null)
    scrollToBottom(true)

    await deliver(clientId)
  }

  function discard(clientId: string) {
    outgoingRef.current.delete(clientId)
    setMessages((current) => removeByClientId(current, clientId))
  }

  async function toggleHide(message: ChatMessage) {
    try {
      const { message: updated } = await api<{ message: ChatMessage }>(
        `/messenger/${message.id}/toggle-hide`,
        { method: 'PATCH' },
      )
      setMessages((current) => current.map((m) => (m.id === updated.id ? updated : m)))
      toastSuccess(
        updated.isHidden ? 'پیام برای ساکنین پنهان شد.' : 'پیام دوباره نمایش داده می‌شود.',
      )
    } catch (error) {
      alertError(error, 'تغییر وضعیت پیام ممکن نشد.')
    }
  }

  const bodyField = register('body')

  if (isLoading) return <InlineSpinner />
  if (loadError) return <ErrorState error={loadError} />

  const readOnlyGroup = conversation && !conversation.canPost

  return (
    <div className="flex h-full min-h-0 flex-col gap-3">
      <header className="flex items-center justify-between gap-2">
        <h2 className="text-body font-bold" style={{ color: 'var(--text-primary)' }}>
          {conversation?.title}
        </h2>
        {conversation?.type === 'unit' || conversation?.type === 'user' ? (
          <span
            className="flex items-center gap-1 text-tiny"
            style={{ color: 'var(--text-tertiary)' }}
          >
            <Lock size={12} /> خصوصی
          </span>
        ) : null}
      </header>

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
                className={cn('chat-row flex', message.isMine ? 'justify-start' : 'justify-end')}
              >
                <Bubble
                  message={message}
                  isAdmin={meta.isAdmin}
                  onHide={() => void toggleHide(message)}
                  onRetry={() => message.clientId && void deliver(message.clientId)}
                  onDiscard={() => message.clientId && discard(message.clientId)}
                  onPollChange={(poll) =>
                    setMessages((current) =>
                      current.map((m) => (m.id === message.id ? { ...m, poll } : m)),
                    )
                  }
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

      {meta.canSend ? (
        <div className="flex flex-col">
          {pollDraft && meta.isAdmin && (
            <PollComposer
              draft={pollDraft}
              onChange={setPollDraft}
              onClose={() => setPollDraft(null)}
            />
          )}

          {attachment && (
            <div
              className="mb-2 flex items-center gap-2 rounded-lg px-3 py-2 text-small"
              style={{ backgroundColor: 'var(--surface-sunken)', color: 'var(--text-secondary)' }}
            >
              <Paperclip size={13} />
              <span className="flex-1 truncate">{attachment.name}</span>
              <button type="button" onClick={() => pickAttachment(null)} aria-label="حذف پیوست">
                <X size={14} />
              </button>
            </div>
          )}

          <form onSubmit={submitForm} className="flex items-start gap-2">
            <div className="flex-1">
              <textarea
                rows={1}
                aria-label="متنِ پیام"
                placeholder="پیام خود را بنویسید…"
                className="w-full resize-none rounded-xl border px-3.5 py-3 text-body outline-none focus:ring-2"
                style={{
                  backgroundColor: 'var(--surface-sunken)',
                  borderColor: errors.body ? 'var(--color-danger)' : 'var(--border-subtle)',
                  color: 'var(--text-primary)',
                  ['--tw-ring-color' as string]: 'var(--ring-focus)',
                }}
                {...bodyField}
                ref={(element) => {
                  bodyField.ref(element)
                  textareaRef.current = element
                }}
              />

              <div className="mt-1 flex items-center gap-1">
                <EmojiPicker onPick={insertEmoji} />
                <button
                  type="button"
                  onClick={() => fileInputRef.current?.click()}
                  aria-label="پیوست فایل"
                  className="flex h-9 w-9 items-center justify-center rounded-lg"
                  style={{ color: 'var(--text-tertiary)' }}
                >
                  <Paperclip size={16} />
                </button>
                <input
                  ref={fileInputRef}
                  type="file"
                  accept={ACCEPTED_TYPES}
                  className="hidden"
                  onChange={(event) => {
                    pickAttachment(event.target.files?.[0] ?? null)
                    event.target.value = ''
                  }}
                />
                {meta.isAdmin && (
                  <button
                    type="button"
                    onClick={() => setPollDraft((draft) => (draft ? null : emptyPoll()))}
                    aria-label="ساخت نظرسنجی"
                    aria-pressed={pollDraft !== null}
                    className="flex h-9 w-9 items-center justify-center rounded-lg"
                    style={{ color: pollDraft ? 'var(--color-brand-500)' : 'var(--text-tertiary)' }}
                  >
                    <BarChart3 size={16} />
                  </button>
                )}
              </div>
              {errors.body && (
                <p className="mt-1 text-small" style={{ color: 'var(--color-danger)' }}>
                  {errors.body.message}
                </p>
              )}
            </div>

            <button
              type="submit"
              disabled={isSubmitting}
              aria-label="ارسال پیام"
              className="flex h-[46px] w-[46px] shrink-0 items-center justify-center rounded-xl text-white disabled:opacity-60"
              style={{ backgroundColor: 'var(--color-brand-500)' }}
            >
              {isSubmitting ? <Loader2 size={17} className="animate-spin" /> : <Send size={17} />}
            </button>
          </form>
        </div>
      ) : (
        <div
          className="flex items-center gap-2 rounded-xl px-4 py-3 text-small"
          style={{ backgroundColor: 'var(--surface-sunken)', color: 'var(--text-secondary)' }}
        >
          <Lock size={15} />
          {readOnlyGroup
            ? 'در این گفت‌وگو فقط مدیریت پیام می‌فرستد.'
            : 'امکان ارسال پیام برای شما فعال نیست.'}
        </div>
      )}
    </div>
  )
}

const STATUS_LABEL: Record<DeliveryStatus, string> = {
  pending: 'در حال ارسال',
  failed: 'ارسال نشد',
  sent: 'ارسال شد',
  read: 'خوانده شد',
}

function StatusIcon({ status }: { status: DeliveryStatus }) {
  const Icon = { pending: Clock, failed: AlertCircle, sent: Check, read: CheckCheck }[status]

  return (
    <span className="flex items-center" title={STATUS_LABEL[status]}>
      <Icon size={12} aria-hidden="true" />
      <span className="sr-only">{STATUS_LABEL[status]}</span>
    </span>
  )
}

function Bubble({
  message,
  isAdmin,
  onHide,
  onRetry,
  onDiscard,
  onPollChange,
}: {
  message: ChatMessage
  isAdmin: boolean
  onHide: () => void
  onRetry: () => void
  onDiscard: () => void
  onPollChange: (poll: NonNullable<ChatMessage['poll']>) => void
}) {
  const muted = message.isMine ? 'rgba(255,255,255,0.75)' : 'var(--text-tertiary)'

  return (
    <div
      className={cn(
        'max-w-[80%] rounded-2xl px-4 py-2.5 text-body',
        message.isHidden && 'opacity-50',
        message.status === 'pending' && 'opacity-70',
      )}
      style={
        message.isMine
          ? { backgroundColor: 'var(--color-brand-500)', color: '#fff' }
          : { backgroundColor: 'var(--surface-sunken)', color: 'var(--text-primary)' }
      }
    >
      <div className="mb-1 flex items-center gap-2 text-tiny" style={{ color: muted }}>
        <span className="font-semibold">{message.authorName}</span>
        {message.unitLabel && (
          <>
            <span>·</span>
            <span>{message.unitLabel}</span>
          </>
        )}
      </div>

      {message.body === null ? (
        <p className="flex items-center gap-1.5 text-small italic opacity-80">
          <EyeOff size={12} /> این پیام توسط مدیر پنهان شده است.
        </p>
      ) : (
        <>
          {message.body && <p className="whitespace-pre-line leading-6">{message.body}</p>}

          {message.attachment &&
            (message.attachment.kind === 'image' ? (
              /* cls-safe: ابعاد از ظرفِ ثابت می‌آید */
              <a
                href={message.attachment.url}
                target="_blank"
                rel="noreferrer"
                className="mt-2 block h-64 w-full max-w-xs"
              >
                <img
                  src={message.attachment.url}
                  alt={message.attachment.name}
                  loading="lazy"
                  decoding="async"
                  className="h-full w-full rounded-lg object-contain object-right"
                />
              </a>
            ) : (
              <a
                href={message.attachment.url}
                target="_blank"
                rel="noreferrer"
                className="mt-2 flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-small underline"
                style={{
                  backgroundColor: message.isMine
                    ? 'rgba(255,255,255,0.18)'
                    : 'var(--surface-base)',
                }}
              >
                <FileText size={13} />
                {message.attachment.name}
              </a>
            ))}

          {message.poll && (
            <PollCard
              poll={message.poll}
              isMine={message.isMine}
              isAdmin={isAdmin}
              onVoted={onPollChange}
            />
          )}
        </>
      )}

      <div className="mt-1.5 flex items-center gap-2 text-tiny" style={{ color: muted }}>
        {message.sentAt && <span className="tabular-nums">{message.sentAt}</span>}
        {message.isMine && message.status && <StatusIcon status={message.status} />}

        {typeof message.readCount === 'number' && message.readCount > 0 && (
          <span className="flex items-center gap-0.5 tabular-nums" title="شمارِ خواننده‌ها">
            <Eye size={11} />
            {message.readCount}
          </span>
        )}

        {message.status === 'failed' && (
          <>
            <button type="button" onClick={onRetry} className="flex items-center gap-1 underline">
              <RotateCcw size={11} /> دوباره
            </button>
            <button type="button" onClick={onDiscard} className="underline">
              حذف
            </button>
          </>
        )}

        {isAdmin && message.id > 0 && (
          <button
            type="button"
            onClick={onHide}
            className="flex items-center gap-1 underline"
            title={message.isHidden ? 'نمایش پیام' : 'مخفی کردن پیام'}
          >
            {message.isHidden ? <Eye size={11} /> : <EyeOff size={11} />}
            {message.isHidden ? 'نمایش' : 'مخفی'}
          </button>
        )}
      </div>
    </div>
  )
}
