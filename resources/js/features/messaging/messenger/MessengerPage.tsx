import { useCallback, useEffect, useRef, useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { AnimatePresence, motion } from 'framer-motion'
import {
  Send,
  Loader2,
  EyeOff,
  Eye,
  MessageSquare,
  Lock,
  Paperclip,
  FileText,
  BarChart3,
  X,
  CheckCheck,
} from 'lucide-react'
import { z } from 'zod'
import { ErrorState, InlineSpinner } from '@/shared/ui/PageState'
import { useDocumentTitle } from '@/shared/hooks'
import { useAuth } from '@/shared/stores/authStore'
import { api, ApiError } from '@/shared/lib/api'
import { alertError, toastSuccess } from '@/shared/lib/alert'
import { cn } from '@/shared/lib/cn'
import { AudiencePicker, type Audience, type MessengerUnit } from './AudiencePicker'
import { EmojiPicker } from './EmojiPicker'
import { PollCard, type MessagePoll } from './PollCard'
import { PollComposer, emptyPoll, type PollDraft } from './PollComposer'

/** همان قیدِ `StoreMessageRequest`؛ اینجا فقط تا کاربر پیش از آپلود بفهمد. */
const MAX_ATTACHMENT_BYTES = 4 * 1024 * 1024
const ACCEPTED_TYPES = '.jpg,.jpeg,.png,.webp,.pdf'

/*
 * `body` اینجا اختیاری است و اجبارش در `onSubmit` بررسی می‌شود، چون پیام با
 * پیوست یا نظرسنجی می‌تواند بی‌متن باشد — همان قاعده‌ای که سرور دارد.
 */
const messageSchema = z.object({
  body: z.string().max(1000, 'پیام بیش از حد طولانی است'),
})

type MessageFormValues = z.infer<typeof messageSchema>

interface ChatMessage {
  id: number
  /** پیامِ مخفی‌شده برای ساکنین متنی ندارد؛ سرور اصلاً نمی‌فرستدش. */
  body: string | null
  authorName: string
  unitLabel: string
  isMine: boolean
  isHidden: boolean
  sentAt: string
  /** مخاطبِ پیام (R23) — تا فرستنده ببیند پیامش کجا رفته. */
  audience?: 'management' | 'all' | 'units'
  audienceLabel?: string
  /** پیوست (R23b)؛ `url` مسیرِ سروِ کنترل‌شده است، نه لینکِ مستقیمِ دیسک. */
  attachment?: { name: string; kind: 'image' | 'file'; url: string } | null
  /** نظرسنجیِ درون‌چت (R23b). */
  poll?: MessagePoll | null
  /** تعدادِ خواننده‌ها؛ سرور این را فقط به مدیر می‌دهد. */
  readCount?: number | null
  /** پیامِ خوش‌بینانه که هنوز پاسخِ سرور برایش نیامده. */
  pending?: boolean
}

interface MessengerResponse {
  messages: ChatMessage[]
  /** شناسه‌ی همه‌ی پیام‌های مخفی‌شده، برای پاک‌کردن نسخه‌های کهنه‌ی کلاینت. */
  hiddenIds: number[]
  /** آیا پیام قدیمی‌تری بیرون از پنجره‌ی بارگذاری‌شده مانده؟ */
  hasOlder?: boolean
  canSend: boolean
  reason: string | null
  isAdmin?: boolean
  /** فهرستِ واحدها برای انتخابگرِ گیرنده؛ فقط برای مدیر پر می‌شود (R23). */
  units?: MessengerUnit[]
}

/** پیامی که کاربر باید خوانده‌شده اعلامش کند: مالِ خودش نیست و پنهان نشده. */
function isReadable(message: ChatMessage): boolean {
  return !message.isMine && !message.pending && message.id > 0
}

const POLL_INTERVAL = 8000

export function MessengerPage() {
  const { user } = useAuth()
  const [messages, setMessages] = useState<ChatMessage[]>([])
  const [meta, setMeta] = useState<{ canSend: boolean; reason: string | null; isAdmin: boolean }>({
    canSend: false,
    reason: null,
    isAdmin: false,
  })
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [hasOlder, setHasOlder] = useState(false)
  const [units, setUnits] = useState<MessengerUnit[]>([])

  const listRef = useRef<HTMLDivElement>(null)
  const lastIdRef = useRef(0)
  const fileInputRef = useRef<HTMLInputElement>(null)
  const textareaRef = useRef<HTMLTextAreaElement | null>(null)

  const [attachment, setAttachment] = useState<File | null>(null)
  const [pollDraft, setPollDraft] = useState<PollDraft | null>(null)

  /*
   * شناسه‌هایی که این نشست خوانده‌شده اعلامشان کرده‌ایم.
   *
   * ref است و نه state: هدفش فقط جلوگیری از ارسالِ تکراری است و تغییرش
   * نباید رندر بیندازد. سرور هم idempotent است، پس این صرفاً صرفه‌جویی
   * در درخواست است، نه تکیه‌گاهِ درستی.
   */
  const reportedRef = useRef<Set<number>>(new Set())

  useDocumentTitle('پیام‌رسان')

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

  // اسکرول به پایین فقط وقتی کاربر خودش ته لیست است؛ اگر بالا رفته و در حال
  // خواندن پیام‌های قدیمی است، نباید پرتش کنیم پایین.
  const scrollToBottom = useCallback((force = false) => {
    const el = listRef.current
    if (!el) return

    const nearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 120
    if (force || nearBottom) {
      requestAnimationFrame(() => {
        el.scrollTop = el.scrollHeight
      })
    }
  }, [])

  const load = useCallback(async (incremental: boolean) => {
    try {
      const query = incremental && lastIdRef.current ? `?since=${lastIdRef.current}` : ''
      const data = await api<MessengerResponse>(`/messenger${query}`)

      setMeta({ canSend: data.canSend, reason: data.reason, isAdmin: Boolean(data.isAdmin) })
      if (data.units) setUnits(data.units)
      if (!incremental) setHasOlder(Boolean(data.hasOlder))

      if (data.messages.length > 0) {
        lastIdRef.current = Math.max(...data.messages.map((m) => m.id))
      }

      setMessages((current) => {
        const hidden = new Set(data.hiddenIds ?? [])
        const isAdmin = Boolean(data.isAdmin)

        /*
         * پیامی که پس از بارگذاری مخفی شده، در واکشی افزایشی برنمی‌گردد
         * (چون شناسه‌اش قدیمی‌تر از `since` است). پس نسخه‌ی محلی را با
         * فهرست hiddenIds هماهنگ می‌کنیم، وگرنه متنی که مدیر پنهان کرده
         * تا وقتی کاربر صفحه را رفرش نکند روی صفحه‌اش می‌ماند.
         */
        const sync = (list: ChatMessage[]) =>
          list.map((m) =>
            hidden.has(m.id) && !m.isHidden
              ? { ...m, isHidden: true, body: isAdmin ? m.body : null }
              : m,
          )

        if (!incremental) return sync(data.messages)

        // فقط پیام‌هایی که هنوز نداریم اضافه شوند
        const known = new Set(current.map((m) => m.id))
        const fresh = data.messages.filter((m) => !known.has(m.id))

        return sync(fresh.length ? [...current, ...fresh] : current)
      })

      setError(null)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'ارتباط با سرور برقرار نشد.')
    } finally {
      setIsLoading(false)
    }
  }, [])

  useEffect(() => {
    void load(false).then(() => scrollToBottom(true))

    /*
     * دریافت پیام‌های جدید، فقط وقتی تب دیده می‌شود.
     *
     * پیش از این تایمر بی‌قید کار می‌کرد؛ یک تب بازِ رهاشده روزی حدود ۱۰٬۸۰۰
     * درخواست به سرور می‌زد برای کاربری که اصلاً به صفحه نگاه نمی‌کند.
     */
    let timer: ReturnType<typeof setInterval> | null = null

    const start = () => {
      if (timer === null) timer = setInterval(() => void load(true), POLL_INTERVAL)
    }
    const stop = () => {
      if (timer !== null) {
        clearInterval(timer)
        timer = null
      }
    }

    const onVisibility = () => {
      if (document.hidden) {
        stop()
      } else {
        // بازگشت به تب: یک‌بار فوری به‌روزرسانی، نه انتظار تا تیک بعدی
        void load(true)
        start()
      }
    }

    if (!document.hidden) start()
    document.addEventListener('visibilitychange', onVisibility)

    return () => {
      stop()
      document.removeEventListener('visibilitychange', onVisibility)
    }
  }, [load, scrollToBottom])

  useEffect(() => {
    scrollToBottom()
  }, [messages, scrollToBottom])

  /*
   * رسیدِ خوانده‌شده (R23b).
   *
   * وقتی کاربر صفحه‌ی پیام‌رسان را باز کرده و تب دیده می‌شود، پیام‌هایی که
   * مالِ خودش نیستند خوانده‌شده اعلام می‌شوند. عمداً به IntersectionObserver
   * گره نخورده: در یک گفت‌وگوی کوتاه تقریباً همه‌ی پیام‌ها در قابِ دید
   * می‌آیند و پیچیدگی‌اش چیزی به دقت اضافه نمی‌کرد.
   *
   * شکستش بی‌صداست: رسیدِ خواندن یک راحتی است، نه چیزی که ارزشِ نشان‌دادنِ
   * خطا به کاربر را داشته باشد.
   */
  useEffect(() => {
    if (document.hidden) return

    const unreported = messages
      .filter((message) => isReadable(message) && !reportedRef.current.has(message.id))
      .map((message) => message.id)

    if (unreported.length === 0) return

    unreported.forEach((id) => reportedRef.current.add(id))

    const timer = setTimeout(() => {
      void api('/messenger/read', { method: 'POST', body: { ids: unreported } }).catch(() => {
        unreported.forEach((id) => reportedRef.current.delete(id))
      })
    }, 1200)

    return () => clearTimeout(timer)
  }, [messages])

  /*
   * انتخابِ گیرنده فقط برای مدیر معنا دارد. ساکن این حالت را هم دارد ولی
   * هرگز استفاده نمی‌شود؛ سرور مخاطبِ پیامِ او را خودش تعیین می‌کند.
   */
  const [audience, setAudience] = useState<Audience>('all')
  const [selectedUnits, setSelectedUnits] = useState<number[]>([])

  /** درجِ اموجی در محلِ مکان‌نما، نه چسباندن به انتهای متن. */
  function insertEmoji(emoji: string) {
    const field = textareaRef.current
    if (!field) return

    const start = field.selectionStart ?? field.value.length
    const end = field.selectionEnd ?? start
    const next = field.value.slice(0, start) + emoji + field.value.slice(end)

    setValue('body', next, { shouldValidate: true })

    requestAnimationFrame(() => {
      field.focus()
      field.setSelectionRange(start + emoji.length, start + emoji.length)
    })
  }

  function pickAttachment(file: File | null) {
    if (file && file.size > MAX_ATTACHMENT_BYTES) {
      setError('حجم فایل باید کمتر از ۴ مگابایت باشد.')
      return
    }

    setError(null)
    setAttachment(file)
  }

  /** جایگزینیِ یک پیام در فهرست — نظرسنجی پس از رأی از این راه به‌روز می‌شود. */
  function replaceMessage(updated: ChatMessage) {
    setMessages((current) => current.map((m) => (m.id === updated.id ? updated : m)))
  }

  // یک‌بار صدا زده می‌شود تا هم RHF ref خودش را بگیرد و هم ما به المان برسیم
  const bodyField = register('body')

  function toggleUnit(unitId: number) {
    setSelectedUnits((current) =>
      current.includes(unitId) ? current.filter((id) => id !== unitId) : [...current, unitId],
    )
  }

  async function onSubmit(values: MessageFormValues) {
    const hasPoll = Boolean(pollDraft?.question.trim())

    // همان قاعده‌ی سرور: متن فقط وقتی اجباری است که پیوست و نظرسنجی نباشد
    if (!values.body.trim() && !attachment && !hasPoll) {
      setFormError('body', { message: 'متن پیام را وارد کنید' })
      return
    }

    if (hasPoll && pollDraft!.options.filter((option) => option.text.trim()).length < 2) {
      setError('نظرسنجی باید دست‌کم دو گزینه داشته باشد.')
      return
    }

    /*
     * ارسالِ خوش‌بینانه: پیام بی‌درنگ در گفت‌وگو ظاهر می‌شود (با نشانه‌ی «در حال
     * ارسال»)، فرم خالی و اسکرول پایین می‌رود. با پاسخِ سرور، نسخه‌ی موقت با
     * پیامِ واقعی جایگزین می‌شود؛ اگر ارسال شکست بخورد، پیام برداشته و متن به
     * فرم برمی‌گردد تا کاربر دوباره تلاش کند. این حسِ آنیِ چت را می‌دهد.
     */
    const tempId = -Date.now()
    const optimistic: ChatMessage = {
      id: tempId,
      body: values.body || (attachment ? attachment.name : ''),
      authorName: user?.name ?? 'شما',
      unitLabel: '',
      isMine: true,
      isHidden: false,
      sentAt: 'در حال ارسال…',
      pending: true,
    }

    setError(null)
    setMessages((current) => [...current, optimistic])
    reset()
    scrollToBottom(true)

    /*
     * وقتی پیوست هست، درخواست باید multipart برود. `api` خودش FormData را
     * تشخیص می‌دهد و Content-Type را دست نمی‌زند، پس فقط بدنه فرق می‌کند.
     */
    const sentAttachment = attachment
    const sentPoll = hasPoll ? pollDraft : null
    setAttachment(null)
    setPollDraft(null)

    /*
     * مقادیرِ FormData رشته‌اند، پس payload از همان اول رشته/آرایه‌ی رشته
     * نگه داشته می‌شود — نه `unknown` که بعد کورکورانه String() شود.
     */
    const payload: Record<string, string | string[]> = { body: values.body }

    if (meta.isAdmin) {
      payload.audience = audience
      payload.unit_ids = audience === 'units' ? selectedUnits.map(String) : []

      if (sentPoll) {
        payload.poll_question = sentPoll.question.trim()
        payload.poll_options = sentPoll.options.map((o) => o.text.trim()).filter(Boolean)
        payload.poll_voter_scope = sentPoll.voterScope
        payload.poll_weight_mode = sentPoll.weightMode
        payload.poll_allow_change = sentPoll.allowChange ? '1' : '0'

        // فیلدهای خالی اصلاً فرستاده نمی‌شوند تا `nullable` سرور کار کند
        if (sentPoll.quorumPercent) payload.poll_quorum_percent = sentPoll.quorumPercent
        if (sentPoll.closesAt) payload.poll_closes_at = new Date(sentPoll.closesAt).toISOString()
      }
    }

    let body: unknown = payload

    if (sentAttachment) {
      const form = new FormData()
      form.append('attachment', sentAttachment)

      for (const [key, value] of Object.entries(payload)) {
        if (Array.isArray(value)) {
          value.forEach((item) => form.append(`${key}[]`, item))
        } else {
          form.append(key, value)
        }
      }

      body = form
    }

    try {
      const { message } = await api<{ message: ChatMessage }>('/messenger', {
        method: 'POST',
        body,
      })

      lastIdRef.current = Math.max(lastIdRef.current, message.id)
      setMessages((current) => current.map((m) => (m.id === tempId ? message : m)))
    } catch (err) {
      setMessages((current) => current.filter((m) => m.id !== tempId))
      reset({ body: values.body })
      setAttachment(sentAttachment)
      setPollDraft(sentPoll)
      setError(err instanceof ApiError ? err.message : 'ارسال پیام ناموفق بود.')
    }
  }

  async function toggleHide(message: ChatMessage) {
    // خوش‌بینانه: وضعیتِ نمایش/پنهان فوری برعکس می‌شود؛ با شکست، به حالت قبل برمی‌گردد.
    const previous = message
    setMessages((current) =>
      current.map((m) => (m.id === message.id ? { ...m, isHidden: !m.isHidden } : m)),
    )

    try {
      const { message: updated } = await api<{ message: ChatMessage }>(
        `/messenger/${message.id}/toggle-hide`,
        { method: 'PATCH' },
      )

      setMessages((current) => current.map((m) => (m.id === updated.id ? updated : m)))
      toastSuccess(
        updated.isHidden ? 'پیام برای ساکنین پنهان شد.' : 'پیام دوباره نمایش داده می‌شود.',
      )
    } catch (err) {
      setMessages((current) => current.map((m) => (m.id === previous.id ? previous : m)))
      alertError(err, 'تغییر وضعیت پیام ممکن نشد.')
    }
  }

  if (isLoading) return <InlineSpinner />
  if (error && messages.length === 0)
    return <ErrorState message={error} onRetry={() => void load(false)} />

  return (
    <div className="flex h-[calc(100vh-8rem)] flex-col gap-4">
      <header>
        <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
          پیام‌رسان
        </h1>
        <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
          گفت‌وگوی داخلی ساکنین و مدیریت مجتمع
        </p>
      </header>

      <div
        ref={listRef}
        className="scrollbar-thin flex-1 overflow-y-auto rounded-2xl border p-4"
        style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
      >
        {messages.length === 0 ? (
          <div className="flex h-full flex-col items-center justify-center gap-2 text-center">
            <MessageSquare size={30} style={{ color: 'var(--text-tertiary)' }} />
            <p className="text-sm" style={{ color: 'var(--text-secondary)' }}>
              هنوز پیامی رد و بدل نشده است.
            </p>
          </div>
        ) : (
          <ul className="flex flex-col gap-3">
            {/* پنجره‌ی بارگذاری محدود است؛ نباید وانمود کنیم تاریخچه از اینجا شروع شده */}
            {hasOlder && (
              <li
                className="mx-auto rounded-full px-3 py-1 text-[11px]"
                style={{ backgroundColor: 'var(--surface-sunken)', color: 'var(--text-tertiary)' }}
              >
                فقط ۲۰۰ پیام آخر نمایش داده می‌شود
              </li>
            )}

            <AnimatePresence initial={false}>
              {messages.map((message) => (
                <motion.li
                  key={message.id}
                  initial={{ opacity: 0, y: 10 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ duration: 0.22 }}
                  className={cn('flex', message.isMine ? 'justify-start' : 'justify-end')}
                >
                  <div
                    className={cn(
                      'max-w-[80%] rounded-2xl px-4 py-2.5 text-[13.5px] transition-opacity',
                      message.isHidden && 'opacity-50',
                      message.pending && 'opacity-70',
                    )}
                    style={
                      message.isMine
                        ? { backgroundColor: 'var(--color-brand-500)', color: '#fff' }
                        : { backgroundColor: 'var(--surface-sunken)', color: 'var(--text-primary)' }
                    }
                  >
                    <div
                      className="mb-1 flex items-center gap-2 text-[11px]"
                      style={{
                        color: message.isMine ? 'rgba(255,255,255,0.75)' : 'var(--text-tertiary)',
                      }}
                    >
                      <span className="font-semibold">{message.authorName}</span>
                      <span>·</span>
                      <span>{message.unitLabel}</span>
                    </div>

                    {message.body === null ? (
                      <p className="flex items-center gap-1.5 text-[12.5px] italic leading-6 opacity-80">
                        <EyeOff size={12} />
                        این پیام توسط مدیر پنهان شده است.
                      </p>
                    ) : (
                      <>
                        {message.body && (
                          <p className="whitespace-pre-line leading-6">{message.body}</p>
                        )}

                        {message.attachment &&
                          (message.attachment.kind === 'image' ? (
                            <a
                              href={message.attachment.url}
                              target="_blank"
                              rel="noreferrer"
                              /*
                               * ارتفاعِ ثابت، نه `max-h` (R36).
                               *
                               * ⚠️ ابعادِ این تصویر را کاربر تعیین می‌کند، پس
                               * `width`/`height` قابلِ نوشتن نیست. با `max-h`
                               * تنها، ارتفاعِ تصویر تا لحظه‌ی رسیدنش صفر است و
                               * بعد ناگهان تا ۲۵۶ پیکسل باز می‌شود — یعنی کلِ
                               * گفت‌وگو زیرِ دستِ کاربر می‌پرد. ظرفِ ثابت همان
                               * فضا را از اول رزرو می‌کند.
                               */
                              /* cls-safe: ابعاد از ظرفِ ثابت می‌آید */
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
                              className="mt-2 flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-[12px] underline"
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
                            isAdmin={meta.isAdmin}
                            onVoted={(poll) => replaceMessage({ ...message, poll })}
                          />
                        )}
                      </>
                    )}

                    <div
                      className="mt-1.5 flex items-center gap-2 text-[10px]"
                      style={{
                        color: message.isMine ? 'rgba(255,255,255,0.7)' : 'var(--text-tertiary)',
                      }}
                    >
                      <span className="tabular-nums">{message.sentAt}</span>

                      {/* رسیدِ خواندن فقط برای مدیر؛ ساکن نباید بداند چند همسایه پیام را باز کرده‌اند */}
                      {typeof message.readCount === 'number' && message.readCount > 0 && (
                        <span className="flex items-center gap-0.5 tabular-nums">
                          <CheckCheck size={11} />
                          {message.readCount}
                        </span>
                      )}

                      {meta.isAdmin && (
                        <button
                          onClick={() => toggleHide(message)}
                          className="flex items-center gap-1 underline"
                          title={message.isHidden ? 'نمایش پیام' : 'مخفی کردن پیام'}
                        >
                          {message.isHidden ? <Eye size={11} /> : <EyeOff size={11} />}
                          {message.isHidden ? 'نمایش' : 'مخفی'}
                        </button>
                      )}
                    </div>
                  </div>
                </motion.li>
              ))}
            </AnimatePresence>
          </ul>
        )}
      </div>

      {meta.canSend ? (
        <div className="flex flex-col">
          {meta.isAdmin ? (
            <AudiencePicker
              audience={audience}
              units={units}
              selected={selectedUnits}
              onAudienceChange={setAudience}
              onToggleUnit={toggleUnit}
            />
          ) : (
            /* ساکن باید بداند پیامش خصوصی است و همسایه‌ها نمی‌بینند */
            <p className="pb-2 text-xs" style={{ color: 'var(--text-tertiary)' }}>
              این پیام فقط برای مدیریت ساختمان فرستاده می‌شود.
            </p>
          )}

          {pollDraft && meta.isAdmin && (
            <PollComposer
              draft={pollDraft}
              onChange={setPollDraft}
              onClose={() => setPollDraft(null)}
            />
          )}

          {attachment && (
            <div
              className="mb-2 flex items-center gap-2 rounded-lg px-3 py-2 text-[12px]"
              style={{ backgroundColor: 'var(--surface-sunken)', color: 'var(--text-secondary)' }}
            >
              <Paperclip size={13} />
              <span className="flex-1 truncate">{attachment.name}</span>
              <button
                type="button"
                onClick={() => pickAttachment(null)}
                aria-label="حذف پیوست"
                style={{ color: 'var(--text-tertiary)' }}
              >
                <X size={14} />
              </button>
            </div>
          )}

          <form onSubmit={handleSubmit(onSubmit)} className="flex items-start gap-2">
            <div className="flex-1">
              <textarea
                rows={1}
                placeholder="پیام خود را بنویسید…"
                className="w-full resize-none rounded-xl border px-3.5 py-3 text-[13.5px] outline-none transition-all focus:ring-2"
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

                {/* نظرسنجی تصمیمِ ساختمان است، پس فقط مدیر می‌سازدش */}
                {meta.isAdmin && (
                  <button
                    type="button"
                    onClick={() => setPollDraft((draft) => (draft ? null : emptyPoll()))}
                    aria-label="ساخت نظرسنجی"
                    aria-pressed={pollDraft !== null}
                    className="flex h-9 w-9 items-center justify-center rounded-lg"
                    style={{
                      color: pollDraft ? 'var(--color-brand-500)' : 'var(--text-tertiary)',
                    }}
                  >
                    <BarChart3 size={16} />
                  </button>
                )}
              </div>
              {errors.body && (
                <p className="mt-1 text-xs" style={{ color: 'var(--color-danger)' }}>
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
          className="flex items-center gap-2 rounded-xl px-4 py-3 text-[13px]"
          style={{ backgroundColor: 'var(--surface-sunken)', color: 'var(--text-secondary)' }}
        >
          <Lock size={15} />
          {meta.reason ?? 'امکان ارسال پیام برای شما فعال نیست.'}
        </div>
      )}
    </div>
  )
}
