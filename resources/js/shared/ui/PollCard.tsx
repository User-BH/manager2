import { useState } from 'react'
import { BarChart3, Check, Loader2, Lock, Trophy, Users, TriangleAlert } from 'lucide-react'
import { api } from '@/shared/lib/api'
import { alertError } from '@/shared/lib/alert'
import { cn } from '@/shared/lib/cn'

export interface MessagePollOption {
  id: number
  label: string
  /** تعدادِ رأی‌دهنده — با شمارشِ وزنی لزوماً برابرِ `weight` نیست. */
  votes: number
  /** وزنِ جمع‌شده: نفر، واحد، یا متر مربع بسته به `weightMode`. */
  weight: number
  /** سهم از **آرای داده‌شده**، نه از کلِ جامعه‌ی آماری. */
  share: number
}

export interface MessagePoll {
  id: number
  question: string
  isClosed: boolean
  closesAt: string | null
  voterScope: 'residents' | 'owners'
  voterScopeLabel: string
  weightMode: 'per_person' | 'per_unit' | 'by_area'
  weightModeLabel: string
  /** واحدِ نمایشیِ نتیجه: «رأی» یا «متر مربع». */
  weightUnit: string
  allowChange: boolean

  options: MessagePollOption[]
  totalVotes: number
  myOptionId: number | null
  /** چرا این کاربر نمی‌تواند رأی بدهد؛ `null` یعنی می‌تواند. */
  blockReason: string | null

  eligibleWeight: number
  castWeight: number
  turnoutPercent: number
  quorumPercent: number | null
  quorumMet: boolean
  /** نظرسنجیِ وزنی که متراژِ واحدها ثبت نشده — نتیجه‌اش بی‌معناست. */
  weightUnavailable: boolean

  /** برنده فقط پس از بسته‌شدن اعلام می‌شود. */
  leaderId: number | null
  isTie: boolean
}

/**
 * نظرسنجیِ درون‌چت (R23b) با نتیجه‌گیریِ حرفه‌ای (R24).
 *
 * نتیجه **همیشه** دیده می‌شود، نه فقط پس از رأی‌دادن. ولی «۳ رأی به بله» یک
 * عدد است نه یک تصمیم؛ پس درصدِ مشارکت و وضعیتِ حد نصاب کنارش می‌آیند و
 * برنده تا بسته‌نشدنِ نظرسنجی اعلام نمی‌شود — اعلامِ برنده وسطِ رأی‌گیری
 * خودش روی رأی‌های بعدی اثر می‌گذارد.
 */
export function PollCard({
  poll,
  onVoted,
  isMine,
  isAdmin = false,
}: {
  poll: MessagePoll
  onVoted: (poll: MessagePoll) => void
  isMine: boolean
  isAdmin?: boolean
}) {
  const [pendingId, setPendingId] = useState<number | null>(null)
  const [isClosing, setIsClosing] = useState(false)

  const muted = isMine ? 'rgba(255,255,255,0.75)' : 'var(--text-tertiary)'
  const track = isMine ? 'rgba(255,255,255,0.18)' : 'var(--surface-base)'
  const fill = isMine ? 'rgba(255,255,255,0.42)' : 'var(--color-brand-500)'

  const canVote = poll.blockReason === null

  async function vote(optionId: number) {
    if (!canVote || pendingId !== null) return

    setPendingId(optionId)

    /*
     * به‌روزرسانیِ خوش‌بینانه، با در نظر گرفتنِ **تعویضِ** رأی: شمارشِ گزینه‌ی
     * قبلی یکی کم می‌شود. اگر این نبود، کاربری که نظرش را عوض می‌کند تا
     * واکشیِ بعدی جمعی می‌دید که از تعدادِ رأی‌دهنده‌ها بیشتر است.
     *
     * وزن اینجا حدس زده نمی‌شود (متراژِ واحدِ خودِ کاربر را نمی‌دانیم)؛ پاسخِ
     * سرور نتیجه‌ی دقیق را برمی‌گرداند و جایگزینش می‌کند.
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
      const { poll: fresh } = await api<{ poll: MessagePoll }>(`/messenger/polls/${poll.id}/vote`, {
        method: 'POST',
        body: { option_id: optionId },
      })

      onVoted(fresh)
    } catch (err) {
      onVoted(poll) // بازگشت به وضعیتِ پیش از کلیک
      alertError(err, 'ثبت رأی ممکن نشد.')
    } finally {
      setPendingId(null)
    }
  }

  async function close() {
    setIsClosing(true)

    try {
      const { poll: fresh } = await api<{ poll: MessagePoll }>(
        `/messenger/polls/${poll.id}/close`,
        { method: 'POST' },
      )

      onVoted(fresh)
    } catch (err) {
      alertError(err, 'بستن نظرسنجی ممکن نشد.')
    } finally {
      setIsClosing(false)
    }
  }

  return (
    <div
      className="mt-2 rounded-xl border p-3"
      style={{ borderColor: isMine ? 'rgba(255,255,255,0.25)' : 'var(--border-subtle)' }}
    >
      <p className="mb-1 flex items-center gap-1.5 text-[12.5px] font-bold">
        <BarChart3 size={13} />
        {poll.question}
      </p>

      {/* قواعدِ نظرسنجی پیش از گزینه‌ها، تا کاربر بداند رأیش چطور شمرده می‌شود */}
      <p
        className="mb-2 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[10.5px]"
        style={{ color: muted }}
      >
        <span className="flex items-center gap-1">
          <Users size={10} />
          {poll.voterScopeLabel}
        </span>
        <span>·</span>
        <span>{poll.weightModeLabel}</span>
        {!poll.allowChange && (
          <>
            <span>·</span>
            <span className="flex items-center gap-1">
              <Lock size={10} />
              رأی قابل تغییر نیست
            </span>
          </>
        )}
      </p>

      <ul className="flex flex-col gap-1.5">
        {poll.options.map((option) => {
          const isMyVote = poll.myOptionId === option.id
          const isLeader = poll.leaderId === option.id

          return (
            <li key={option.id}>
              <button
                type="button"
                onClick={() => void vote(option.id)}
                disabled={!canVote || pendingId !== null}
                aria-pressed={isMyVote}
                className="relative w-full overflow-hidden rounded-lg px-2.5 py-1.5 text-right text-[12px] disabled:cursor-default"
                style={{ backgroundColor: track }}
              >
                {/* نوارِ نتیجه پشتِ برچسب، نه کنارش — عرضِ حباب چت کم است */}
                <span
                  className="absolute inset-y-0 right-0 transition-[width] duration-300"
                  style={{ width: `${option.share}%`, backgroundColor: fill, opacity: 0.5 }}
                  aria-hidden
                />
                <span className="relative flex items-center justify-between gap-2">
                  <span className={cn('flex items-center gap-1.5', isLeader && 'font-bold')}>
                    {pendingId === option.id ? (
                      <Loader2 size={11} className="animate-spin" />
                    ) : (
                      isMyVote && <Check size={11} />
                    )}
                    {isLeader && <Trophy size={11} />}
                    {option.label}
                  </span>
                  <span className="tabular-nums" style={{ color: muted }}>
                    {option.share}٪
                  </span>
                </span>
              </button>
            </li>
          )
        })}
      </ul>

      {/* ── آمار ─────────────────────────────────────────────────────────── */}
      <div className="mt-2 flex flex-col gap-0.5 text-[10.5px]" style={{ color: muted }}>
        <p className="tabular-nums">
          مشارکت {poll.turnoutPercent}٪ ({poll.castWeight} از {poll.eligibleWeight}{' '}
          {poll.weightUnit})
          {poll.quorumPercent !== null && (
            <>
              {' · '}
              حد نصاب {poll.quorumPercent}٪{' '}
              <span style={{ color: poll.quorumMet ? undefined : 'var(--color-danger)' }}>
                {poll.quorumMet ? '✓' : '✗'}
              </span>
            </>
          )}
        </p>

        {poll.isTie && <p>نتیجه مساوی شد.</p>}

        {poll.weightUnavailable && (
          <p className="flex items-center gap-1" style={{ color: 'var(--color-warning)' }}>
            <TriangleAlert size={10} />
            متراژ واحدها ثبت نشده؛ نتیجه‌ی وزنی معتبر نیست.
          </p>
        )}

        {poll.blockReason && <p>{poll.blockReason}</p>}
      </div>

      {isAdmin && !poll.isClosed && (
        <button
          type="button"
          onClick={() => void close()}
          disabled={isClosing}
          className="mt-2 flex items-center gap-1 text-[11px] underline disabled:opacity-60"
          style={{ color: muted }}
        >
          {isClosing ? <Loader2 size={10} className="animate-spin" /> : <Lock size={10} />}
          بستن نظرسنجی و اعلام نتیجه
        </button>
      )}
    </div>
  )
}
