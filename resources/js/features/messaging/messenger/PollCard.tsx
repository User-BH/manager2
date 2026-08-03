import { useState } from 'react'
import { BarChart3, Check, Loader2 } from 'lucide-react'
import { api } from '@/shared/lib/api'
import { alertError } from '@/shared/lib/alert'

export interface MessagePollOption {
  id: number
  label: string
  votes: number
}

export interface MessagePoll {
  id: number
  question: string
  isClosed: boolean
  totalVotes: number
  /** گزینه‌ای که خودِ بیننده انتخاب کرده؛ `null` یعنی هنوز رأی نداده. */
  myOptionId: number | null
  options: MessagePollOption[]
}

/**
 * نظرسنجیِ درون‌چت (R23b).
 *
 * نتیجه **همیشه** دیده می‌شود، نه فقط پس از رأی‌دادن. در یک ساختمان،
 * پنهان‌کردنِ نتیجه چیزی را منصفانه‌تر نمی‌کند و فقط باعث می‌شود کسی که
 * هنوز رأی نداده نداند اصلاً موضوع چیست.
 */
export function PollCard({
  poll,
  onVoted,
  isMine,
}: {
  poll: MessagePoll
  onVoted: (poll: MessagePoll) => void
  isMine: boolean
}) {
  const [pendingId, setPendingId] = useState<number | null>(null)

  const muted = isMine ? 'rgba(255,255,255,0.75)' : 'var(--text-tertiary)'
  const track = isMine ? 'rgba(255,255,255,0.18)' : 'var(--surface-base)'
  const fill = isMine ? 'rgba(255,255,255,0.42)' : 'var(--color-brand-500)'

  async function vote(optionId: number) {
    if (poll.isClosed || pendingId !== null) return

    setPendingId(optionId)

    /*
     * به‌روزرسانیِ خوش‌بینانه، با در نظر گرفتنِ **تعویضِ** رأی: شمارشِ گزینه‌ی
     * قبلی یکی کم می‌شود. اگر این نبود، کاربری که نظرش را عوض می‌کند تا
     * واکشیِ بعدی جمعی می‌دید که از تعدادِ رأی‌دهنده‌ها بیشتر است.
     */
    const previousId = poll.myOptionId

    onVoted({
      ...poll,
      myOptionId: optionId,
      totalVotes: previousId === null ? poll.totalVotes + 1 : poll.totalVotes,
      options: poll.options.map((option) => ({
        ...option,
        votes:
          option.id === optionId
            ? option.votes + 1
            : option.id === previousId
              ? option.votes - 1
              : option.votes,
      })),
    })

    try {
      await api(`/messenger/polls/${poll.id}/vote`, {
        method: 'POST',
        body: { option_id: optionId },
      })
    } catch (err) {
      onVoted(poll) // بازگشت به وضعیتِ پیش از کلیک
      alertError(err, 'ثبت رأی ممکن نشد.')
    } finally {
      setPendingId(null)
    }
  }

  return (
    <div
      className="mt-2 rounded-xl border p-3"
      style={{ borderColor: isMine ? 'rgba(255,255,255,0.25)' : 'var(--border-subtle)' }}
    >
      <p className="mb-2 flex items-center gap-1.5 text-[12.5px] font-bold">
        <BarChart3 size={13} />
        {poll.question}
      </p>

      <ul className="flex flex-col gap-1.5">
        {poll.options.map((option) => {
          const share = poll.totalVotes > 0 ? Math.round((option.votes / poll.totalVotes) * 100) : 0
          const isMyVote = poll.myOptionId === option.id

          return (
            <li key={option.id}>
              <button
                type="button"
                onClick={() => void vote(option.id)}
                disabled={poll.isClosed || pendingId !== null}
                aria-pressed={isMyVote}
                className="relative w-full overflow-hidden rounded-lg px-2.5 py-1.5 text-right text-[12px] disabled:cursor-default"
                style={{ backgroundColor: track }}
              >
                {/* نوارِ نتیجه پشتِ برچسب، نه کنارش — عرضِ حباب چت کم است */}
                <span
                  className="absolute inset-y-0 right-0 transition-[width] duration-300"
                  style={{ width: `${share}%`, backgroundColor: fill, opacity: 0.5 }}
                  aria-hidden
                />
                <span className="relative flex items-center justify-between gap-2">
                  <span className="flex items-center gap-1.5">
                    {pendingId === option.id ? (
                      <Loader2 size={11} className="animate-spin" />
                    ) : (
                      isMyVote && <Check size={11} />
                    )}
                    {option.label}
                  </span>
                  <span className="tabular-nums" style={{ color: muted }}>
                    {share}٪
                  </span>
                </span>
              </button>
            </li>
          )
        })}
      </ul>

      <p className="mt-2 text-[10.5px]" style={{ color: muted }}>
        {poll.totalVotes} رأی
        {poll.isClosed && ' · این نظرسنجی بسته شده است'}
      </p>
    </div>
  )
}
