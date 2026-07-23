import { useCallback, useEffect, useRef, useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { AnimatePresence, motion } from 'framer-motion'
import { Send, Loader2, EyeOff, Eye, MessageSquare, Lock } from 'lucide-react'
import { z } from 'zod'
import { ErrorState, InlineSpinner } from '@/components/ui/PageState'
import { useDocumentTitle } from '@/hooks'
import { api, ApiError } from '@/lib/api'
import { alertError, toastSuccess } from '@/lib/alert'
import { cn } from '@/lib/cn'

const messageSchema = z.object({
  body: z.string().min(1, 'متن پیام را وارد کنید').max(1000, 'پیام بیش از حد طولانی است'),
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
}

interface MessengerResponse {
  messages: ChatMessage[]
  /** شناسه‌ی همه‌ی پیام‌های مخفی‌شده، برای پاک‌کردن نسخه‌های کهنه‌ی کلاینت. */
  hiddenIds: number[]
  canSend: boolean
  reason: string | null
  isAdmin?: boolean
}

const POLL_INTERVAL = 8000

export function MessengerPage() {
  const [messages, setMessages] = useState<ChatMessage[]>([])
  const [meta, setMeta] = useState<{ canSend: boolean; reason: string | null; isAdmin: boolean }>({
    canSend: false,
    reason: null,
    isAdmin: false,
  })
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const listRef = useRef<HTMLDivElement>(null)
  const lastIdRef = useRef(0)

  useDocumentTitle('پیام‌رسان')

  const {
    register,
    handleSubmit,
    reset,
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

  const load = useCallback(
    async (incremental: boolean) => {
      try {
        const query = incremental && lastIdRef.current ? `?since=${lastIdRef.current}` : ''
        const data = await api<MessengerResponse>(`/messenger${query}`)

        setMeta({ canSend: data.canSend, reason: data.reason, isAdmin: Boolean(data.isAdmin) })

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
    },
    [],
  )

  useEffect(() => {
    void load(false).then(() => scrollToBottom(true))

    // دریافت پیام‌های جدید؛ فقط شناسه‌های بعد از آخرین پیام درخواست می‌شود
    const timer = setInterval(() => void load(true), POLL_INTERVAL)

    return () => clearInterval(timer)
  }, [load, scrollToBottom])

  useEffect(() => {
    scrollToBottom()
  }, [messages, scrollToBottom])

  async function onSubmit(values: MessageFormValues) {
    try {
      const { message } = await api<{ message: ChatMessage }>('/messenger', {
        method: 'POST',
        body: values,
      })

      lastIdRef.current = Math.max(lastIdRef.current, message.id)
      setMessages((current) => [...current, message])
      reset()
      scrollToBottom(true)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'ارسال پیام ناموفق بود.')
    }
  }

  async function toggleHide(message: ChatMessage) {
    try {
      const { message: updated } = await api<{ message: ChatMessage }>(
        `/messenger/${message.id}/toggle-hide`,
        { method: 'PATCH' },
      )

      setMessages((current) => current.map((m) => (m.id === updated.id ? updated : m)))
      toastSuccess(updated.isHidden ? 'پیام برای ساکنین پنهان شد.' : 'پیام دوباره نمایش داده می‌شود.')
    } catch (err) {
      alertError(err, 'تغییر وضعیت پیام ممکن نشد.')
    }
  }

  if (isLoading) return <InlineSpinner />
  if (error && messages.length === 0) return <ErrorState message={error} onRetry={() => void load(false)} />

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
                      'max-w-[80%] rounded-2xl px-4 py-2.5 text-[13.5px]',
                      message.isHidden && 'opacity-50',
                    )}
                    style={
                      message.isMine
                        ? { backgroundColor: 'var(--color-brand-500)', color: '#fff' }
                        : { backgroundColor: 'var(--surface-sunken)', color: 'var(--text-primary)' }
                    }
                  >
                    <div
                      className="mb-1 flex items-center gap-2 text-[11px]"
                      style={{ color: message.isMine ? 'rgba(255,255,255,0.75)' : 'var(--text-tertiary)' }}
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
                      <p className="whitespace-pre-line leading-6">{message.body}</p>
                    )}

                    <div
                      className="mt-1.5 flex items-center gap-2 text-[10px]"
                      style={{ color: message.isMine ? 'rgba(255,255,255,0.7)' : 'var(--text-tertiary)' }}
                    >
                      <span className="tabular-nums">{message.sentAt}</span>

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
              {...register('body')}
            />
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
