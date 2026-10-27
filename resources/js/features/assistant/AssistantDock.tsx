import { useEffect, useId, useRef, useState, type Dispatch, type SetStateAction } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2, Send, Sparkles, Trash2, X } from 'lucide-react'
import { api } from '@/shared/lib/api'
import { alertError } from '@/shared/lib/alert'
import { queryKeys } from '@/shared/lib/queryKeys'
import { questionSchema, type QuestionValues } from './schema'
import type {
  AssistantAnswer,
  AssistantAskResponse,
  AssistantHistory,
  AssistantStatus,
  AssistantTurn,
} from './types'

const STATUS_LABEL: Partial<Record<AssistantStatus, string>> = {
  not_found: 'داده‌ای پیدا نشد',
  forbidden: 'اجازه‌ی دیدن ندارید',
  clarify: 'سؤالِ تکمیلی',
  unsupported: 'خارج از داده‌های دستیار',
  error: 'خطا',
}

/**
 * دستیارِ داخلیِ داشبورد (مرحلهٔ ۱۳ · فاز ۸؛ ایرادات ۱۱، بخشِ ۴).
 *
 * دکمه‌ی دایره‌ایِ شناورِ **پایینِ چپ** (بندِ ۴.۳)، مستقل از منوی کناریِ سمتِ راست و نوارِ بالا؛ کلِ ماژول تنبل است و فقط
 * برای حسابی بار می‌شود که سرور گفته دستیار دارد (`user.assistant`). پاسخ‌ها از سرورند — داده‌ی واقعیِ مجاز، با سه حالتِ
 * «پیدا شد / نیست / اجازه نیست»، بازه و مبنای محاسبه. گفت‌وگو مالِ همین حساب در همین زمینه است: بستن و بازکردنِ پنل آن
 * را نگه می‌دارد، و تعویضِ مجتمع (بارگذاریِ کامل) گفت‌وگوی همان مجتمع را می‌آورد.
 */
export function AssistantDock() {
  const [open, setOpen] = useState(false)
  // نوبت‌های همین نشست بیرونِ پنل — پنلِ بسته‌شده unmount می‌شود و گفت‌وگو نباید با آن برود
  const [turns, setTurns] = useState<AssistantTurn[]>([])
  const launcher = useRef<HTMLButtonElement>(null)
  const panelId = useId()

  function close() {
    setOpen(false)
    launcher.current?.focus()
  }

  return (
    <>
      {open && <AssistantPanel id={panelId} onClose={close} turns={turns} setTurns={setTurns} />}
      <button
        ref={launcher}
        type="button"
        onClick={() => (open ? close() : setOpen(true))}
        aria-label={open ? 'بستنِ دستیار' : 'دستیارِ هوشمندِ ساکنا'}
        aria-expanded={open}
        aria-controls={open ? panelId : undefined}
        title="دستیارِ هوشمندِ ساکنا — پرسش درباره‌ی داده‌های همین داشبورد"
        className="support-chat-frame fixed bottom-4 left-4 z-40 flex h-14 w-14 items-center justify-center rounded-full p-[1.5px] shadow-xl transition-transform hover:scale-105 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-offset-2 active:scale-95"
        style={{ ['--tw-ring-color' as string]: 'var(--color-brand-300)' }}
      >
        <span
          className="flex h-full w-full items-center justify-center rounded-full"
          style={{
            background: 'linear-gradient(135deg, var(--color-brand-600), var(--color-brand-400))',
          }}
        >
          {open ? (
            <X size={22} className="text-white" />
          ) : (
            <Sparkles size={22} className="text-white" />
          )}
        </span>
      </button>
    </>
  )
}

interface PanelProps {
  id: string
  onClose: () => void
  turns: AssistantTurn[]
  setTurns: Dispatch<SetStateAction<AssistantTurn[]>>
}

function AssistantPanel({ id, onClose, turns, setTurns }: PanelProps) {
  const queryClient = useQueryClient()
  const history = useQuery({
    queryKey: queryKeys.assistant.history(),
    queryFn: ({ signal }) => api<AssistantHistory>('/assistant/history', { signal }),
    staleTime: 30_000,
  })
  const [confirmClear, setConfirmClear] = useState(false)
  const scroller = useRef<HTMLDivElement>(null)
  const input = useRef<HTMLInputElement | null>(null)

  const {
    register,
    handleSubmit,
    setValue,
    setError,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<QuestionValues>({
    resolver: zodResolver(questionSchema),
    defaultValues: { question: '' },
  })
  const { ref: inputRef, ...questionField } = register('question')

  const past: AssistantTurn[] = (history.data?.messages ?? []).map((m) => ({
    key: `h${m.id}`,
    question: m.question,
    createdAt: m.createdAt,
    status: m.status,
    text: m.text,
  }))
  const shownIds = new Set(turns.map((t) => t.key))
  const conversation = [...past.filter((t) => !shownIds.has(t.key)), ...turns]

  useEffect(() => {
    input.current?.focus()
    const onKey = (event: KeyboardEvent) => event.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  useEffect(() => {
    // پاسخِ تازه پایینِ گفت‌وگوست؛ `scrollTop` در هر موتوری هست (`scrollTo` ِ عنصر نه)
    if (scroller.current) scroller.current.scrollTop = scroller.current.scrollHeight
  }, [conversation.length, isSubmitting])

  async function ask(values: QuestionValues) {
    try {
      const response = await api<AssistantAskResponse>('/assistant/ask', {
        method: 'POST',
        body: { question: values.question },
      })
      setTurns((current) => [
        ...current,
        {
          key: response.id ? `h${response.id}` : `n${current.length}`,
          question: response.question,
          createdAt: response.createdAt,
          status: response.answer.status,
          text: response.answer.text,
          answer: response.answer,
        },
      ])
      reset({ question: '' })
    } catch (error) {
      setError('root', { message: error instanceof Error ? error.message : 'پرسش فرستاده نشد.' })
      alertError(error, 'پرسش فرستاده نشد.')
    }
  }

  async function clear() {
    if (!confirmClear) {
      setConfirmClear(true)
      return
    }
    try {
      await api('/assistant/history', { method: 'DELETE' })
      setTurns([])
      setConfirmClear(false)
      await queryClient.invalidateQueries({ queryKey: queryKeys.assistant.history() })
    } catch (error) {
      alertError(error, 'پاک‌کردنِ گفت‌وگو ممکن نشد.')
    }
  }

  function suggest(text: string) {
    setValue('question', text, { shouldValidate: false })
    input.current?.focus()
  }

  return (
    <div
      id={id}
      role="dialog"
      aria-label="دستیارِ هوشمندِ ساکنا"
      dir="rtl"
      className="fixed inset-x-2 bottom-20 z-40 flex max-h-[min(78vh,36rem)] flex-col overflow-hidden rounded-3xl border shadow-2xl sm:inset-x-auto sm:left-4 sm:w-[25rem]"
      style={{ backgroundColor: 'var(--surface-base)', borderColor: 'var(--border-subtle)' }}
    >
      <header
        className="flex items-center gap-3 px-4 py-3"
        style={{
          background: 'linear-gradient(135deg, var(--color-brand-600), var(--color-brand-400))',
        }}
      >
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/20">
          <Sparkles size={18} className="text-white" />
        </span>
        <div className="min-w-0 flex-1">
          <p className="text-sm font-bold text-white">دستیارِ ساکنا</p>
          <p className="truncate text-small text-white/85">
            داده‌های «{history.data?.context ?? '…'}» — داخلی، بی سرویسِ بیرونی
          </p>
        </div>
        <button
          type="button"
          onClick={() => void clear()}
          aria-label={confirmClear ? 'تأییدِ پاک‌کردنِ گفت‌وگو' : 'پاک‌کردنِ گفت‌وگو'}
          title={confirmClear ? 'برای پاک‌کردن دوباره بزنید' : 'پاک‌کردنِ گفت‌وگو'}
          className="flex h-8 items-center gap-1 rounded-lg px-2 text-small text-white/90 transition-colors hover:bg-white/15"
        >
          <Trash2 size={16} />
          {confirmClear && <span>مطمئنید؟</span>}
        </button>
        <button
          type="button"
          onClick={onClose}
          aria-label="بستنِ دستیار"
          className="flex h-8 w-8 items-center justify-center rounded-lg text-white/90 transition-colors hover:bg-white/15"
        >
          <X size={17} />
        </button>
      </header>

      <div
        ref={scroller}
        className="scrollbar-thin flex-1 space-y-3 overflow-y-auto px-3.5 py-4"
        aria-live="polite"
        aria-busy={isSubmitting}
      >
        {history.isLoading && (
          <p className="text-center text-small" style={{ color: 'var(--text-tertiary)' }}>
            در حالِ بارگذاریِ گفت‌وگو…
          </p>
        )}
        {history.isError && (
          <p role="alert" className="text-small" style={{ color: 'var(--color-danger)' }}>
            گفت‌وگوی قبلی بار نشد؛ پرسشِ تازه را می‌توانید بفرستید.
          </p>
        )}

        {conversation.length === 0 && history.data && (
          <div className="flex flex-col gap-1.5">
            <p className="text-small" style={{ color: 'var(--text-secondary)' }}>
              درباره‌ی داده‌های همین داشبورد بپرسید؛ پاسخ از داده‌ی واقعیِ مجاز ساخته می‌شود. نمونه:
            </p>
            {history.data.suggestions.map((s) => (
              <SuggestionButton key={s} text={s} onPick={suggest} />
            ))}
          </div>
        )}

        {conversation.map((turn) => (
          <Turn key={turn.key} turn={turn} onPick={suggest} />
        ))}

        {isSubmitting && (
          <p
            className="flex items-center gap-2 text-small"
            style={{ color: 'var(--text-tertiary)' }}
          >
            <Loader2 size={14} className="animate-spin" /> در حالِ بررسیِ داده‌ها…
          </p>
        )}
      </div>

      <form
        onSubmit={(event) => void handleSubmit(ask)(event)}
        noValidate
        className="border-t px-3 py-2.5"
        style={{ borderColor: 'var(--border-subtle)' }}
      >
        <div className="flex items-center gap-2">
          <input
            {...questionField}
            ref={(element) => {
              inputRef(element)
              input.current = element
            }}
            placeholder="مثلاً: هزینه‌های ماهِ گذشته چقدر بود؟"
            maxLength={300}
            aria-label="پرسش از دستیار"
            aria-invalid={errors.question ? true : undefined}
            className="field min-w-0 flex-1 rounded-xl px-3 py-2 text-sm"
          />
          <button
            type="submit"
            disabled={isSubmitting}
            aria-label="فرستادنِ پرسش"
            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-white transition-opacity disabled:opacity-50"
            style={{ backgroundColor: 'var(--color-brand-500)' }}
          >
            {isSubmitting ? <Loader2 size={16} className="animate-spin" /> : <Send size={16} />}
          </button>
        </div>
        {(errors.question?.message ?? errors.root?.message) && (
          <p role="alert" className="mt-1.5 text-small" style={{ color: 'var(--color-danger)' }}>
            {errors.question?.message ?? errors.root?.message}
          </p>
        )}
      </form>
    </div>
  )
}

function Turn({ turn, onPick }: { turn: AssistantTurn; onPick: (text: string) => void }) {
  const label = STATUS_LABEL[turn.status]
  const warn = turn.status === 'forbidden' || turn.status === 'error'

  return (
    <div className="flex flex-col gap-1.5">
      <div className="flex justify-start">
        <p
          className="max-w-[85%] rounded-2xl px-3.5 py-2 text-sm leading-7 text-white"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          {turn.question}
        </p>
      </div>
      <div className="flex justify-end">
        <div
          className="max-w-[94%] rounded-2xl px-3.5 py-2.5 text-sm leading-7"
          style={{
            backgroundColor: warn
              ? 'color-mix(in srgb, var(--color-danger) 9%, var(--surface-sunken))'
              : 'var(--surface-sunken)',
            color: 'var(--text-primary)',
          }}
        >
          {label && (
            <span
              className="mb-1 inline-block rounded-full px-2 text-small font-semibold"
              style={{
                backgroundColor: warn
                  ? 'color-mix(in srgb, var(--color-danger) 14%, transparent)'
                  : 'color-mix(in srgb, var(--color-brand-500) 14%, transparent)',
                color: warn ? 'var(--color-danger)' : 'var(--color-brand-600)',
              }}
            >
              {label}
            </span>
          )}
          {turn.answer ? (
            <AnswerBody answer={turn.answer} onPick={onPick} />
          ) : (
            turn.text.split('\n').map((line, i) => <p key={i}>{line}</p>)
          )}
          <p className="mt-1 text-small" style={{ color: 'var(--text-tertiary)' }}>
            {turn.createdAt}
          </p>
        </div>
      </div>
    </div>
  )
}

function AnswerBody({
  answer,
  onPick,
}: {
  answer: AssistantAnswer
  onPick: (text: string) => void
}) {
  return (
    <div className="flex flex-col gap-2">
      <p className="font-semibold">{answer.headline}</p>

      {answer.facts.length > 0 && (
        <dl className="flex flex-col gap-1">
          {answer.facts.map((fact) => (
            <div key={fact.label} className="flex flex-wrap justify-between gap-x-3">
              <dt style={{ color: 'var(--text-tertiary)' }}>{fact.label}</dt>
              <dd className="font-bold">{fact.value}</dd>
            </div>
          ))}
        </dl>
      )}

      {answer.rows.length > 0 && (
        <div className="scrollbar-thin overflow-x-auto">
          <table className="w-full text-small">
            {answer.columns.length > 0 && (
              <thead>
                <tr>
                  {answer.columns.map((column) => (
                    <th
                      key={column}
                      className="px-1.5 py-1 text-right font-semibold"
                      style={{ color: 'var(--text-tertiary)' }}
                    >
                      {column}
                    </th>
                  ))}
                </tr>
              </thead>
            )}
            <tbody>
              {answer.rows.map((row, i) => (
                <tr key={i} className="border-t" style={{ borderColor: 'var(--border-subtle)' }}>
                  {row.map((cell, j) => (
                    <td key={j} className="whitespace-nowrap px-1.5 py-1">
                      {cell}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {answer.more && (
        <p className="text-small" style={{ color: 'var(--text-tertiary)' }}>
          موارد بیشتری هم هست؛ فهرستِ کامل در صفحه‌ی همین بخش است.
        </p>
      )}

      {answer.options.length > 0 && (
        <div className="flex flex-col gap-1.5">
          {answer.options.map((option) => (
            <SuggestionButton key={option} text={option} onPick={onPick} />
          ))}
        </div>
      )}

      {(answer.period || answer.basis) && (
        <div className="text-small" style={{ color: 'var(--text-tertiary)' }}>
          {answer.period && <p>بازه: {answer.period}</p>}
          {answer.note && <p>{answer.note}</p>}
          {answer.basis && <p>مبنا: {answer.basis}</p>}
        </div>
      )}
    </div>
  )
}

function SuggestionButton({ text, onPick }: { text: string; onPick: (text: string) => void }) {
  return (
    <button
      type="button"
      onClick={() => onPick(text)}
      className="rounded-xl border px-3 py-1.5 text-right text-small transition-colors hover:bg-(--surface-sunken)"
      style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
    >
      {text}
    </button>
  )
}
