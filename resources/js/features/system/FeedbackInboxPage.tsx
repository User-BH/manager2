import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  Archive,
  CheckCheck,
  Loader2,
  MessageSquareHeart,
  Phone,
  Reply,
  RotateCcw,
  UserCheck,
  UserX,
} from 'lucide-react'
import { Card } from '@/shared/ui/Card'
import { EmptyState, ErrorState } from '@/shared/ui/PageState'
import { TableSkeleton } from '@/shared/ui/Skeleton'
import { Pager, type PageMeta } from '@/shared/ui/Pager'
import { useApiAction } from '@/shared/hooks/useAction'
import { useDocumentTitle } from '@/shared/hooks'
import { api } from '@/shared/lib/api'
import { formatNumber } from '@/shared/lib/format'
import { toAsciiDigits } from '@/shared/lib/inputFilters'
import { queryKeys } from '@/shared/lib/queryKeys'

type FeedbackStatus = 'new' | 'read' | 'archived'

interface FeedbackRow {
  id: number
  category: string
  categoryLabel: string
  message: string
  name: string | null
  phone: string | null
  status: FeedbackStatus
  statusLabel: string
  createdAt: string | null
  /** تاریخ و ساعتِ جدا، به وقتِ تهران — از سرور (مرحلهٔ ۸ · فاز ۶) */
  date: string | null
  time: string | null
  /** فقط سمتِ سرور تشخیص داده می‌شود؛ `null` یعنی عضوِ سایت نیست */
  member: { name: string; via: 'account' | 'phone' } | null
  replies: { id: number; body: string; author: string | null; at: string | null }[]
}

const REPLY_MAX = 2000

interface FeedbackResponse {
  data: FeedbackRow[]
  meta: PageMeta
  counts: { new: number }
}

const FILTERS: { value: FeedbackStatus | ''; label: string }[] = [
  { value: 'new', label: 'تازه' },
  { value: 'read', label: 'خوانده‌شده' },
  { value: 'archived', label: 'بایگانی' },
  { value: '', label: 'همه' },
]

/**
 * «بازخورد کاربران» — فقط ادمینِ کل (مرحلهٔ ۷ · فاز ۴؛ مرحلهٔ ۸ · فاز ۶).
 *
 * مرحلهٔ ۸ · فاز ۶: هر پیام می‌گوید فرستنده عضوِ سایت هست یا نه (تشخیص فقط در
 * سرور)، تاریخ و ساعت جدا، رشته‌ی پاسخ‌ها، و برای عضو جعبه‌ی پاسخ — پاسخ به‌صورتِ
 * اعلان فقط به همان کاربر می‌رسد.
 *
 * ⚠️ متنِ پیام از بیرون آمده و **همیشه متن** رندر می‌شود (React escape
 * می‌کند؛ هیچ `dangerouslySetInnerHTML`ی اینجا نیست). سطرهای تازه با
 * `whitespace-pre-line` نگه داشته می‌شوند تا پیامِ چندبندی خوانا بماند.
 */
export function FeedbackInboxPage() {
  useDocumentTitle('بازخورد کاربران')
  const [status, setStatus] = useState<FeedbackStatus | ''>('new')
  const [page, setPage] = useState(1)
  const { call, isPending } = useApiAction()

  const params = { status: status || undefined, page }
  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.system.feedback(params),
    queryFn: ({ signal }) => {
      const query = new URLSearchParams({ page: String(page) })
      if (status) query.set('status', status)
      return api<FeedbackResponse>(`/system/feedback?${query.toString()}`, { signal })
    },
  })

  function move(item: FeedbackRow, to: FeedbackStatus, success: string) {
    void call(
      `/system/feedback/${item.id}`,
      { method: 'PATCH', body: { status: to } },
      { key: item.id, success, invalidate: [queryKeys.system.all()] },
    )
  }

  return (
    <div className="flex flex-col gap-5">
      <header>
        <h1
          className="flex items-center gap-2 text-xl font-extrabold"
          style={{ color: 'var(--text-primary)' }}
        >
          <MessageSquareHeart size={22} style={{ color: 'var(--color-brand-500)' }} />
          بازخورد کاربران
        </h1>
        <p className="mt-1 text-body" style={{ color: 'var(--text-tertiary)' }}>
          پیام‌های فرمِ بازخوردِ صفحه‌ی پشتیبانی
          {data ? ` — ${formatNumber(data.counts.new)} پیامِ تازه` : ''}
        </p>
      </header>

      <div className="flex flex-wrap gap-2" role="tablist" aria-label="وضعیت">
        {FILTERS.map((filter) => {
          const active = status === filter.value
          return (
            <button
              key={filter.label}
              type="button"
              role="tab"
              aria-selected={active}
              onClick={() => {
                setStatus(filter.value)
                setPage(1)
              }}
              className="rounded-xl border px-3.5 py-2 text-small font-semibold"
              style={{
                borderColor: active ? 'var(--color-brand-500)' : 'var(--border-subtle)',
                color: active ? 'var(--color-brand-600)' : 'var(--text-secondary)',
                backgroundColor: active
                  ? 'color-mix(in srgb, var(--color-brand-500) 10%, transparent)'
                  : 'transparent',
              }}
            >
              {filter.label}
            </button>
          )
        })}
      </div>

      {isLoading && <TableSkeleton rows={4} columns={3} />}
      {error && <ErrorState error={error} onRetry={() => void refetch()} />}

      {data && data.data.length === 0 && (
        <EmptyState
          message="پیامی در این بخش نیست"
          hint="بازخوردهایی که از صفحه‌ی پشتیبانی فرستاده شوند اینجا می‌آیند."
        />
      )}

      {data && data.data.length > 0 && (
        <>
          <ul className="flex flex-col gap-3">
            {data.data.map((item) => (
              <li key={item.id}>
                <Card>
                  <div className="flex flex-wrap items-center gap-2 text-small">
                    <span
                      className="rounded-full px-2.5 py-0.5 font-bold"
                      style={{
                        backgroundColor: 'var(--tone-brand-bg)',
                        color: 'var(--tone-brand-fg)',
                      }}
                    >
                      {item.categoryLabel}
                    </span>
                    {item.date && (
                      <span style={{ color: 'var(--text-tertiary)' }}>
                        {`تاریخ ${item.date} · ساعت ${item.time ?? ''}`}
                      </span>
                    )}
                    <MemberBadge member={item.member} />
                    {item.replies.length > 0 && (
                      <span
                        className="rounded-full px-2.5 py-0.5 text-xs font-bold"
                        style={{
                          backgroundColor: 'var(--tone-brand-bg)',
                          color: 'var(--tone-brand-fg)',
                        }}
                      >
                        پاسخ داده شد
                      </span>
                    )}
                    <span className="mr-auto" style={{ color: 'var(--text-tertiary)' }}>
                      {item.statusLabel}
                    </span>
                  </div>

                  <p
                    dir="auto"
                    className="mt-3 whitespace-pre-line break-words text-body leading-7"
                    style={{ color: 'var(--text-primary)' }}
                  >
                    {item.message}
                  </p>

                  {(item.name || item.phone) && (
                    <p
                      className="mt-3 flex flex-wrap items-center gap-3 text-small"
                      style={{ color: 'var(--text-secondary)' }}
                    >
                      {item.name && <span>{item.name}</span>}
                      {item.phone && (
                        <a
                          href={`tel:${toAsciiDigits(item.phone)}`}
                          className="inline-flex items-center gap-1 font-semibold"
                          style={{ color: 'var(--color-brand-600)' }}
                          dir="ltr"
                        >
                          <Phone size={13} />
                          {item.phone}
                        </a>
                      )}
                    </p>
                  )}

                  {item.replies.length > 0 && (
                    <ul className="mt-4 flex flex-col gap-2" aria-label="پاسخ‌ها">
                      {item.replies.map((reply) => (
                        <li
                          key={reply.id}
                          className="rounded-xl border-r-4 px-3 py-2 text-small"
                          style={{
                            borderColor: 'var(--color-brand-500)',
                            backgroundColor: 'var(--surface-sunken)',
                          }}
                        >
                          <p
                            className="text-xs font-semibold"
                            style={{ color: 'var(--text-tertiary)' }}
                          >
                            پاسخِ {reply.author ?? 'تیمِ ساکنا'} · {reply.at}
                          </p>
                          {/* متنِ پاسخ همیشه متن رندر می‌شود؛ هیچ HTMLی */}
                          <p
                            dir="auto"
                            className="mt-1 whitespace-pre-line break-words leading-7"
                            style={{ color: 'var(--text-primary)' }}
                          >
                            {reply.body}
                          </p>
                        </li>
                      ))}
                    </ul>
                  )}

                  {item.member ? (
                    <ReplyBox item={item} />
                  ) : (
                    <p className="mt-4 text-xs leading-6" style={{ color: 'var(--text-tertiary)' }}>
                      فرستنده عضوِ سایت نیست؛ پاسخ داخلِ سایت ممکن نیست — با شماره‌اش تماس بگیرید.
                    </p>
                  )}

                  <div className="mt-4 flex flex-wrap gap-2">
                    {item.status === 'new' && (
                      <button
                        type="button"
                        disabled={isPending(item.id)}
                        onClick={() => move(item, 'read', 'خوانده‌شده علامت خورد.')}
                        className="inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-small font-semibold"
                        style={{
                          borderColor: 'var(--border-default)',
                          color: 'var(--text-secondary)',
                        }}
                      >
                        <CheckCheck size={14} />
                        خوانده شد
                      </button>
                    )}
                    {item.status !== 'archived' ? (
                      <button
                        type="button"
                        disabled={isPending(item.id)}
                        onClick={() => move(item, 'archived', 'بایگانی شد.')}
                        className="inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-small font-semibold"
                        style={{
                          borderColor: 'var(--border-default)',
                          color: 'var(--text-secondary)',
                        }}
                      >
                        <Archive size={14} />
                        بایگانی
                      </button>
                    ) : (
                      <button
                        type="button"
                        disabled={isPending(item.id)}
                        onClick={() => move(item, 'read', 'از بایگانی برگشت.')}
                        className="inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-small font-semibold"
                        style={{
                          borderColor: 'var(--border-default)',
                          color: 'var(--text-secondary)',
                        }}
                      >
                        <RotateCcw size={14} />
                        برگرداندن
                      </button>
                    )}
                  </div>
                </Card>
              </li>
            ))}
          </ul>
          <Pager meta={data.meta} onChange={setPage} noun="پیام" />
        </>
      )}
    </div>
  )
}

/** عضو بودن و راهِ تشخیصش — «با حساب» قطعی‌تر از «با شماره» است */
function MemberBadge({ member }: { member: FeedbackRow['member'] }) {
  return member ? (
    <span
      className="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-bold"
      style={{
        backgroundColor: 'var(--tone-success-bg)',
        color: 'var(--tone-success-fg)',
      }}
      title={member.via === 'account' ? 'هنگامِ فرستادن وارد شده بود' : 'از روی شماره‌ی موبایل'}
    >
      <UserCheck size={13} aria-hidden="true" />
      عضو سایت · {member.name}
      <span className="font-normal">({member.via === 'account' ? 'با حساب' : 'با شماره'})</span>
    </span>
  ) : (
    <span
      className="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-bold"
      style={{ backgroundColor: 'var(--surface-sunken)', color: 'var(--text-tertiary)' }}
    >
      <UserX size={13} aria-hidden="true" />
      عضو سایت نیست
    </span>
  )
}

/** جعبه‌ی پاسخ — فقط برای عضو؛ متن پس از ارسالِ موفق پاک می‌شود، نه پیش از آن */
function ReplyBox({ item }: { item: FeedbackRow }) {
  const [body, setBody] = useState('')
  const [error, setError] = useState<string | null>(null)
  const { call, isPending } = useApiAction()
  const pending = isPending(`reply-${item.id}`)
  const id = `reply-${item.id}`
  const errorId = `${id}-error`

  // دکمه با طولِ متن خاموش نمی‌شود (FormConsistencyTest)؛ کلیک پیامِ روشن می‌دهد
  async function send() {
    if (pending) return
    const text = body.trim()
    if (text.length < 2) {
      setError('متنِ پاسخ را بنویسید (دست‌کم ۲ نویسه).')
      return
    }
    setError(null)
    const result = await call(
      `/system/feedback/${item.id}/replies`,
      { method: 'POST', body: { body: text } },
      {
        key: `reply-${item.id}`,
        success: 'پاسخ فرستاده شد و در اعلان‌های کاربر می‌نشیند.',
        invalidate: [queryKeys.system.all()],
      },
    )
    if (result !== undefined) setBody('')
  }

  return (
    <div className="mt-4 flex flex-col gap-2">
      <label
        htmlFor={id}
        className="text-xs font-semibold"
        style={{ color: 'var(--text-secondary)' }}
      >
        پاسخ به {item.member?.name}
      </label>
      <textarea
        id={id}
        rows={3}
        maxLength={REPLY_MAX}
        value={body}
        onChange={(event) => {
          setBody(event.target.value)
          if (error) setError(null)
        }}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? errorId : undefined}
        placeholder="پاسخ در اعلان‌های همین کاربر نشان داده می‌شود."
        className="field w-full rounded-xl px-3 py-2.5 text-[13.5px] leading-7"
      />
      {error && (
        <p id={errorId} role="alert" className="text-xs" style={{ color: 'var(--color-danger)' }}>
          {error}
        </p>
      )}
      <div className="flex items-center justify-between gap-2">
        <span
          className="text-tiny tabular-nums"
          style={{ color: 'var(--text-tertiary)' }}
          dir="ltr"
        >
          {formatNumber(body.length)} / {formatNumber(REPLY_MAX)}
        </span>
        <button
          type="button"
          onClick={() => void send()}
          disabled={pending}
          aria-busy={pending || undefined}
          className="inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-small font-bold text-white disabled:opacity-50"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          {pending ? (
            <Loader2 size={14} className="animate-spin" aria-hidden="true" />
          ) : (
            <Reply size={14} aria-hidden="true" />
          )}
          {pending ? 'در حالِ ارسال…' : 'ارسالِ پاسخ'}
        </button>
      </div>
    </div>
  )
}
