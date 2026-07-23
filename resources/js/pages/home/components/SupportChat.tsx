import { useEffect, useRef, useState } from 'react'
import { AnimatePresence, motion } from 'framer-motion'
import { ExternalLink, Loader2, Send, Sparkles, X } from 'lucide-react'
import { api } from '@/lib/api'

interface ChatLink {
  label: string
  href: string
}

interface BotReply {
  intent: string
  title: string | null
  answer: string
  links: ChatLink[]
  followUps: string[]
  confident: boolean
}

interface ChatMessage {
  id: number
  role: 'user' | 'bot'
  text: string
  links?: ChatLink[]
  followUps?: string[]
}

let messageId = 0

const WELCOME =
  'سلام! 👋 من دستیار پشتیبانی ساکنا هستم. درباره‌ی ثبت‌نام، هزینه‌ها، محاسبه‌ی شارژ، پرداخت، امنیت و هر چیز دیگری بپرسید تا راهنمایی‌تان کنم.'

/**
 * چت پشتیبانی شناور صفحه‌ی فرود.
 *
 * پاسخ‌ها از موتور محلی می‌آیند (`/api/support/chat`)، نه مدل زبانیِ بیرونی:
 * پروژه قید «بدون وابستگی خارجی» دارد و این نقطه عمومی است. کاربر این را
 * حس نمی‌کند چون پاسخ‌ها آنی و مرتبط‌اند و همیشه به بخش درست ارجاع می‌دهند.
 */
export function SupportChat() {
  const [open, setOpen] = useState(false)
  const [messages, setMessages] = useState<ChatMessage[]>([])
  const [draft, setDraft] = useState('')
  const [thinking, setThinking] = useState(false)
  const [starters, setStarters] = useState<string[]>([])
  const scrollRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)

  // سوال‌های پیشنهادی یک‌بار، وقتی چت اولین بار باز می‌شود
  useEffect(() => {
    if (!open || messages.length > 0) return

    setMessages([{ id: messageId++, role: 'bot', text: WELCOME }])

    api<{ starters: string[] }>('/support/starters')
      .then((data) => setStarters(data.starters))
      .catch(() => setStarters([]))
  }, [open, messages.length])

  // هر پیام تازه، گفت‌وگو را به پایین می‌کشد
  useEffect(() => {
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' })
  }, [messages, thinking])

  // فوکوس روی ورودی هنگام باز شدن
  useEffect(() => {
    if (open) setTimeout(() => inputRef.current?.focus(), 350)
  }, [open])

  async function send(text: string) {
    const question = text.trim()
    if (!question || thinking) return

    setMessages((m) => [...m, { id: messageId++, role: 'user', text: question }])
    setDraft('')
    setStarters([])
    setThinking(true)

    try {
      const reply = await api<BotReply>('/support/chat', {
        method: 'POST',
        body: { message: question },
      })

      setMessages((m) => [
        ...m,
        {
          id: messageId++,
          role: 'bot',
          text: reply.answer,
          links: reply.links,
          followUps: reply.followUps,
        },
      ])
    } catch {
      setMessages((m) => [
        ...m,
        {
          id: messageId++,
          role: 'bot',
          text: 'ارتباط با پشتیبانی برقرار نشد. لطفاً چند لحظه بعد دوباره تلاش کنید یا از صفحه‌ی پشتیبانی پیام بدهید.',
          links: [{ label: 'صفحه پشتیبانی', href: '/support' }],
        },
      ])
    } finally {
      setThinking(false)
    }
  }

  return (
    <AnimatePresence>
      {open && (
        <motion.div
          key="panel"
          initial={{ opacity: 0, scale: 0.9, y: 24, originX: 1, originY: 1 }}
          animate={{ opacity: 1, scale: 1, y: 0 }}
          exit={{ opacity: 0, scale: 0.9, y: 24 }}
          transition={{ type: 'spring', stiffness: 320, damping: 28 }}
          className="fixed bottom-24 right-5 z-50 w-[min(92vw,23rem)]"
          dir="rtl"
        >
          {/*
            قاب با نوارِ نورِ رونده: یک لایه‌ی گرادیانِ چرخان پشت پنل، و خودِ
            پنل کمی کوچک‌تر روی آن، طوری که فقط یک خط نازکِ متحرک دیده شود.
          */}
          <div className="support-chat-frame rounded-3xl p-[1.5px] shadow-2xl">
            <div
              className="flex h-[27rem] flex-col overflow-hidden rounded-[calc(1.5rem-1.5px)]"
              style={{ backgroundColor: 'var(--surface-base)' }}
            >
              {/* سرصفحه */}
              <header
                className="flex items-center gap-3 px-4 py-3"
                style={{ background: 'linear-gradient(135deg, var(--color-brand-600), var(--color-brand-400))' }}
              >
                <span className="flex h-9 w-9 items-center justify-center rounded-full bg-white/20">
                  <Sparkles size={18} className="text-white" />
                </span>
                <div className="flex-1">
                  <p className="text-[13.5px] font-bold text-white">دستیار پشتیبانی ساکنا</p>
                  <p className="flex items-center gap-1.5 text-[11px] text-white/85">
                    <span className="inline-block h-1.5 w-1.5 rounded-full bg-emerald-300" />
                    آنلاین — پاسخ آنی
                  </p>
                </div>
                <button
                  onClick={() => setOpen(false)}
                  aria-label="بستن گفت‌وگو"
                  className="flex h-8 w-8 items-center justify-center rounded-lg text-white/90 transition-colors hover:bg-white/15"
                >
                  <X size={17} />
                </button>
              </header>

              {/* گفت‌وگو */}
              <div ref={scrollRef} className="scrollbar-thin flex-1 space-y-3 overflow-y-auto px-3.5 py-4">
                {messages.map((message) => (
                  <ChatBubble key={message.id} message={message} onFollowUp={send} />
                ))}

                {thinking && <TypingIndicator />}

                {starters.length > 0 && !thinking && (
                  <div className="flex flex-col gap-1.5 pt-1">
                    <p className="px-1 text-[11px]" style={{ color: 'var(--text-tertiary)' }}>
                      یا یکی از این‌ها را بپرسید:
                    </p>
                    {starters.map((starter) => (
                      <button
                        key={starter}
                        onClick={() => send(starter)}
                        className="rounded-xl border px-3 py-2 text-right text-[12.5px] transition-colors hover:bg-(--surface-sunken)"
                        style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
                      >
                        {starter}
                      </button>
                    ))}
                  </div>
                )}
              </div>

              {/* ورودی */}
              <form
                onSubmit={(e) => {
                  e.preventDefault()
                  void send(draft)
                }}
                className="flex items-center gap-2 border-t px-3 py-2.5"
                style={{ borderColor: 'var(--border-subtle)' }}
              >
                <input
                  ref={inputRef}
                  value={draft}
                  onChange={(e) => setDraft(e.target.value)}
                  placeholder="سوالتان را بنویسید…"
                  maxLength={500}
                  aria-label="متن پیام"
                  className="flex-1 rounded-xl border px-3 py-2 text-[13px] outline-none focus:ring-2"
                  style={{
                    backgroundColor: 'var(--surface-sunken)',
                    borderColor: 'var(--border-subtle)',
                    color: 'var(--text-primary)',
                    ['--tw-ring-color' as string]: 'var(--ring-focus)',
                  }}
                />
                <button
                  type="submit"
                  disabled={!draft.trim() || thinking}
                  aria-label="ارسال"
                  className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-white transition-opacity disabled:opacity-40"
                  style={{ backgroundColor: 'var(--color-brand-500)' }}
                >
                  {thinking ? <Loader2 size={16} className="animate-spin" /> : <Send size={16} />}
                </button>
              </form>
            </div>
          </div>
        </motion.div>
      )}

      {/* دکمه‌ی شناورِ باز/بسته */}
      <ChatToggle key="toggle" open={open} onToggle={() => setOpen((o) => !o)} />
    </AnimatePresence>
  )
}

function ChatBubble({
  message,
  onFollowUp,
}: {
  message: ChatMessage
  onFollowUp: (text: string) => void
}) {
  const isUser = message.role === 'user'

  return (
    <motion.div
      initial={{ opacity: 0, y: 8 }}
      animate={{ opacity: 1, y: 0 }}
      className={isUser ? 'flex justify-start' : 'flex justify-end'}
    >
      <div className={isUser ? 'max-w-[85%]' : 'max-w-[92%]'}>
        <div
          className="rounded-2xl px-3.5 py-2.5 text-[12.5px] leading-7"
          style={
            isUser
              ? { backgroundColor: 'var(--color-brand-500)', color: '#fff' }
              : { backgroundColor: 'var(--surface-sunken)', color: 'var(--text-primary)' }
          }
        >
          {/* متن با پشتیبانی از **پررنگ** و شکست خط */}
          {message.text.split('\n').map((line, i) => (
            <p key={i} className={i > 0 ? 'mt-1.5' : ''}>
              {renderInline(line)}
            </p>
          ))}
        </div>

        {message.links && message.links.length > 0 && (
          <div className="mt-2 flex flex-wrap gap-1.5">
            {message.links.map((link) => (
              <a
                key={link.href}
                href={link.href}
                className="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11.5px] font-semibold transition-transform hover:scale-105"
                style={{
                  backgroundColor: 'color-mix(in srgb, var(--color-brand-500) 14%, transparent)',
                  color: 'var(--color-brand-600)',
                }}
              >
                {link.label}
                <ExternalLink size={11} />
              </a>
            ))}
          </div>
        )}

        {message.followUps && message.followUps.length > 0 && (
          <div className="mt-2 flex flex-col items-end gap-1.5">
            {message.followUps.map((followUp) => (
              <button
                key={followUp}
                onClick={() => onFollowUp(followUp.replace(/^درباره‌ی /, ''))}
                className="rounded-full border px-3 py-1 text-[11.5px] transition-colors hover:bg-(--surface-sunken)"
                style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-tertiary)' }}
              >
                {followUp}
              </button>
            ))}
          </div>
        )}
      </div>
    </motion.div>
  )
}

/** پررنگ‌کردن متنِ بین `**…**`. */
function renderInline(line: string) {
  return line.split(/(\*\*[^*]+\*\*)/g).map((part, i) =>
    part.startsWith('**') && part.endsWith('**') ? (
      <strong key={i} className="font-bold">
        {part.slice(2, -2)}
      </strong>
    ) : (
      <span key={i}>{part}</span>
    ),
  )
}

function TypingIndicator() {
  return (
    <div className="flex justify-end">
      <div
        className="flex items-center gap-1 rounded-2xl px-4 py-3"
        style={{ backgroundColor: 'var(--surface-sunken)' }}
      >
        {[0, 1, 2].map((i) => (
          <motion.span
            key={i}
            className="inline-block h-1.5 w-1.5 rounded-full"
            style={{ backgroundColor: 'var(--text-tertiary)' }}
            animate={{ opacity: [0.3, 1, 0.3], y: [0, -3, 0] }}
            transition={{ duration: 1, repeat: Infinity, delay: i * 0.15 }}
          />
        ))}
      </div>
    </div>
  )
}

function ChatToggle({ open, onToggle }: { open: boolean; onToggle: () => void }) {
  return (
    <motion.button
      onClick={onToggle}
      aria-label={open ? 'بستن پشتیبانی' : 'گفت‌وگو با پشتیبانی'}
      title={open ? 'بستن' : 'گفت‌وگو با پشتیبانی'}
      className="support-chat-frame fixed bottom-5 right-5 z-50 flex h-14 w-14 items-center justify-center rounded-full p-[1.5px] shadow-xl"
      whileHover={{ scale: 1.08 }}
      whileTap={{ scale: 0.94 }}
    >
      <span
        className="flex h-full w-full items-center justify-center rounded-full"
        style={{ background: 'linear-gradient(135deg, var(--color-brand-600), var(--color-brand-400))' }}
      >
        <AnimatePresence mode="wait">
          {open ? (
            <motion.span key="x" initial={{ rotate: -90, opacity: 0 }} animate={{ rotate: 0, opacity: 1 }} exit={{ rotate: 90, opacity: 0 }}>
              <X size={22} className="text-white" />
            </motion.span>
          ) : (
            <motion.span key="chat" initial={{ rotate: 90, opacity: 0 }} animate={{ rotate: 0, opacity: 1 }} exit={{ rotate: -90, opacity: 0 }}>
              <Sparkles size={22} className="text-white" />
            </motion.span>
          )}
        </AnimatePresence>
      </span>
    </motion.button>
  )
}
