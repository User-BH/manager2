import { useState } from 'react'
import { Link, useLocation, useSearchParams } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import {
  ArrowRight,
  BellRing,
  CalendarDays,
  ChevronLeft,
  ChevronRight,
  ChevronsLeft,
  ChevronsRight,
  History,
  Plus,
  Repeat,
  Trash2,
} from 'lucide-react'
import { api } from '@/shared/lib/api'
import { alertError, confirmAction, toastSuccess } from '@/shared/lib/alert'
import { formatNumber } from '@/shared/lib/format'
import { queryKeys } from '@/shared/lib/queryKeys'
import { useDocumentTitle } from '@/shared/hooks'
import {
  JALALI_MONTHS,
  JALALI_WEEKDAYS,
  faDigits,
  fromIso,
  isoOf,
  jalaliMonthLength,
  jalaliWeekday,
  todayInTehran,
} from '@/shared/lib/jalali'
import { Card } from '@/shared/ui/Card'
import { EmptyState, ErrorState } from '@/shared/ui/PageState'
import { Pager } from '@/shared/ui/Pager'
import { CardListSkeleton } from '@/shared/ui/Skeleton'
import { ReminderForm, faTime, jalaliLabel } from './ReminderForm'
import type {
  ReminderDetail,
  ReminderHistoryResponse,
  ReminderOccurrence,
  ReminderScope,
} from './types'

/** «تاریخچه‌ی یادآورها» — همان صفحه، محتوای دیگر (بندِ ۸۸) */
const REMINDER_HISTORY_PATH = '/calendar/history'

const WEEKDAY_SHORT = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج']

/**
 * تقویمِ شمسی و یادآورها (مرحلهٔ ۱۳ · فاز ۷؛ ایرادات ۱۰، بندهای ۶۶ تا ۹۹).
 *
 * در محتوای اصلی، نه پنجره‌ی کوچک (بندِ ۷۰). ماه و سال از الگوریتمِ آزموده‌ی `shared/lib/jalali.ts` — هم‌سنجِ سرور روز
 * به روز؛ «امروز» به وقتِ تهران. یادآورها شخصی و مالِ زمینه‌ی فعال‌اند و همه‌چیز را سرور تعیین می‌کند
 * (`ReminderController`)؛ این صفحه فقط نشان می‌دهد و می‌فرستد. داشبوردِ ثبت‌نامِ اولیه این صفحه را ندارد.
 */
export function CalendarPage() {
  const history = useLocation().pathname === REMINDER_HISTORY_PATH
  useDocumentTitle(history ? 'تاریخچه یادآورها' : 'تقویم')

  return (
    <div className="flex flex-col gap-5">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <h1
          className="flex items-center gap-2 text-xl font-extrabold"
          style={{ color: 'var(--text-primary)' }}
        >
          {history ? <History size={19} /> : <CalendarDays size={19} />}
          {history ? 'تاریخچه یادآورها' : 'تقویم'}
        </h1>
        <Link
          to={history ? '/calendar' : REMINDER_HISTORY_PATH}
          className="flex items-center gap-1.5 rounded-xl border px-4 py-2.5 text-body font-semibold"
          style={{ borderColor: 'var(--border-default)', color: 'var(--text-secondary)' }}
        >
          {history ? <ArrowRight size={15} /> : <History size={15} />}
          {history ? 'بازگشت به تقویم' : 'تاریخچه یادآورها'}
        </Link>
      </header>
      {history ? <ReminderHistory /> : <MonthView />}
    </div>
  )
}

/* ─── ماه (بندهای ۷۱ تا ۷۶ و ۹۳ تا ۹۶) ─────────────────────────────────── */

function MonthView() {
  const [params] = useSearchParams()
  const today = todayInTehran()
  const todayIso = isoOf(today.jy, today.jm, today.jd)
  // اعلانِ یادآور به همان روز می‌آورد (`?date=`)
  const linked = /^\d{4}-\d{2}-\d{2}$/.test(params.get('date') ?? '')
    ? (params.get('date') as string)
    : null
  const start = linked ? fromIso(linked) : today
  const [view, setView] = useState({ jy: start.jy, jm: start.jm })
  const [selected, setSelected] = useState(linked ?? todayIso)

  const length = jalaliMonthLength(view.jy, view.jm)
  const from = isoOf(view.jy, view.jm, 1)
  const to = isoOf(view.jy, view.jm, length)
  const lead = jalaliWeekday(view.jy, view.jm, 1)

  const { data, error, refetch } = useQuery({
    queryKey: queryKeys.reminders.range(from, to),
    queryFn: ({ signal }) =>
      api<{ data: ReminderOccurrence[] }>(`/reminders?from=${from}&to=${to}`, { signal }),
  })
  const byDay = new Map<string, ReminderOccurrence[]>()
  for (const occurrence of data?.data ?? []) {
    byDay.set(occurrence.date, [...(byDay.get(occurrence.date) ?? []), occurrence])
  }

  function shift(months: number) {
    const index = view.jy * 12 + (view.jm - 1) + months
    setView({ jy: Math.floor(index / 12), jm: (index % 12) + 1 })
  }

  const years = Array.from({ length: 41 }, (_, i) => today.jy - 30 + i)
  if (!years.includes(view.jy)) years.push(view.jy)
  years.sort((a, b) => a - b)

  return (
    <div className="grid gap-5 lg:grid-cols-3">
      <Card className="lg:col-span-2">
        <div className="flex flex-col gap-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="flex items-center gap-1">
              <NavButton
                label="سال قبل"
                onClick={() => shift(-12)}
                icon={<ChevronsRight size={16} />}
              />
              <NavButton
                label="ماه قبل"
                onClick={() => shift(-1)}
                icon={<ChevronRight size={16} />}
              />
            </div>
            {/* در گوشی ماه و سال یک ردیفِ کامل؛ دکمه‌های پیمایش زیرش در دو سو */}
            <div className="order-first flex w-full items-center justify-center gap-2 sm:order-none sm:w-auto">
              <select
                aria-label="ماه"
                value={view.jm}
                onChange={(event) => setView({ ...view, jm: Number(event.target.value) })}
                className="field rounded-xl px-3 py-2 text-sm font-bold"
              >
                {JALALI_MONTHS.map((month, index) => (
                  <option key={month} value={index + 1}>
                    {month}
                  </option>
                ))}
              </select>
              <select
                aria-label="سال"
                value={view.jy}
                onChange={(event) => setView({ ...view, jy: Number(event.target.value) })}
                className="field rounded-xl px-3 py-2 text-sm font-bold"
              >
                {years.map((year) => (
                  <option key={year} value={year}>
                    {faDigits(year)}
                  </option>
                ))}
              </select>
              <button
                type="button"
                onClick={() => {
                  setView({ jy: today.jy, jm: today.jm })
                  setSelected(todayIso)
                }}
                className="rounded-xl border px-3 py-2 text-body font-semibold"
                style={{ borderColor: 'var(--border-default)', color: 'var(--color-brand-600)' }}
              >
                امروز
              </button>
            </div>
            <div className="flex items-center gap-1">
              <NavButton
                label="ماه بعد"
                onClick={() => shift(1)}
                icon={<ChevronLeft size={16} />}
              />
              <NavButton
                label="سال بعد"
                onClick={() => shift(12)}
                icon={<ChevronsLeft size={16} />}
              />
            </div>
          </div>

          <h2
            className="text-center text-lg font-extrabold"
            style={{ color: 'var(--text-primary)' }}
          >
            {JALALI_MONTHS[view.jm - 1]} {faDigits(view.jy)}
          </h2>

          <div role="grid" aria-label={`${JALALI_MONTHS[view.jm - 1]} ${faDigits(view.jy)}`}>
            <div
              role="row"
              className="grid gap-1.5"
              style={{ gridTemplateColumns: 'repeat(7, minmax(0, 1fr))' }}
            >
              {JALALI_WEEKDAYS.map((weekday, index) => (
                <span
                  key={weekday}
                  role="columnheader"
                  aria-label={weekday}
                  className="py-1 text-center text-small font-bold"
                  style={{ color: index === 6 ? 'var(--color-danger)' : 'var(--text-tertiary)' }}
                >
                  {WEEKDAY_SHORT[index]}
                </span>
              ))}
            </div>
            <div
              role="row"
              className="mt-1.5 grid gap-1.5"
              style={{ gridTemplateColumns: 'repeat(7, minmax(0, 1fr))' }}
            >
              {Array.from({ length: lead }, (_, i) => (
                <span key={`blank-${i}`} aria-hidden="true" />
              ))}
              {Array.from({ length }, (_, i) => {
                const day = i + 1
                const iso = isoOf(view.jy, view.jm, day)
                const isToday = iso === todayIso
                const isSelected = iso === selected
                const marks = byDay.get(iso)?.length ?? 0
                const past = iso < todayIso
                return (
                  <button
                    key={iso}
                    type="button"
                    role="gridcell"
                    aria-selected={isSelected}
                    aria-current={isToday ? 'date' : undefined}
                    aria-label={`${jalaliLabel(iso)}${isToday ? '، امروز' : ''}${marks ? `، ${formatNumber(marks)} یادآور` : ''}`}
                    onClick={() => setSelected(iso)}
                    className="relative flex h-12 flex-col items-center justify-center rounded-xl border text-sm font-bold transition hover:shadow-md sm:h-14"
                    style={{
                      borderColor: isToday
                        ? 'var(--color-brand-500)'
                        : isSelected
                          ? 'var(--color-brand-400)'
                          : 'var(--border-subtle)',
                      borderWidth: isToday ? 2 : 1,
                      backgroundColor: isSelected
                        ? 'var(--color-brand-600)'
                        : isToday
                          ? 'color-mix(in srgb, var(--color-brand-500) 12%, transparent)'
                          : undefined,
                      color: isSelected
                        ? 'white'
                        : past
                          ? 'var(--text-tertiary)'
                          : 'var(--text-primary)',
                    }}
                  >
                    {faDigits(day)}
                    {marks > 0 && (
                      <span
                        aria-hidden="true"
                        className="absolute bottom-1.5 h-1.5 w-1.5 rounded-full"
                        style={{
                          backgroundColor: isSelected ? 'white' : 'var(--color-accent-500)',
                        }}
                      />
                    )}
                  </button>
                )
              })}
            </div>
          </div>
          {error && <ErrorState error={error} onRetry={() => void refetch()} />}
        </div>
      </Card>

      <DayPanel
        key={selected}
        iso={selected}
        canCreate={selected >= todayIso}
        occurrences={byDay.get(selected) ?? []}
      />
    </div>
  )
}

function NavButton({
  label,
  onClick,
  icon,
}: {
  label: string
  onClick: () => void
  icon: React.ReactNode
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={label}
      title={label}
      className="flex h-9 w-9 items-center justify-center rounded-full border transition-colors hover:bg-(--surface-sunken)"
      style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
    >
      {icon}
    </button>
  )
}

/* ─── روزِ برگزیده: یادآورها، ساخت، جزئیات، ویرایش، حذف (بندهای ۷۷ تا ۸۵ و ۹۲) ─── */

type Panel =
  | { mode: 'list' }
  | { mode: 'create' }
  | { mode: 'detail' | 'edit'; occurrence: ReminderOccurrence }

function DayPanel({
  iso,
  canCreate,
  occurrences,
}: {
  iso: string
  canCreate: boolean
  occurrences: ReminderOccurrence[]
}) {
  const client = useQueryClient()
  const [panel, setPanel] = useState<Panel>({ mode: 'list' })
  const refresh = () => void client.invalidateQueries({ queryKey: queryKeys.reminders.all() })

  return (
    <Card title={jalaliLabel(iso)}>
      {panel.mode === 'create' && (
        <ReminderForm
          targetIso={iso}
          onCancel={() => setPanel({ mode: 'list' })}
          onDone={() => {
            toastSuccess('یادآور ثبت شد.')
            refresh()
            setPanel({ mode: 'list' })
          }}
        />
      )}

      {(panel.mode === 'detail' || panel.mode === 'edit') && (
        <ReminderView
          occurrence={panel.occurrence}
          editing={panel.mode === 'edit'}
          onEdit={() => setPanel({ mode: 'edit', occurrence: panel.occurrence })}
          onClose={() => setPanel({ mode: 'list' })}
          onChanged={() => {
            refresh()
            setPanel({ mode: 'list' })
          }}
        />
      )}

      {panel.mode === 'list' && (
        <div className="flex flex-col gap-3">
          {occurrences.length === 0 ? (
            <p className="text-body" style={{ color: 'var(--text-tertiary)' }}>
              برای این روز یادآوری ثبت نشده است.
            </p>
          ) : (
            <ul className="flex flex-col gap-2">
              {occurrences.map((occurrence) => (
                <li key={occurrence.id}>
                  <button
                    type="button"
                    onClick={() => setPanel({ mode: 'detail', occurrence })}
                    className="flex w-full flex-col gap-1 rounded-xl border p-3 text-start transition hover:shadow-md"
                    style={{ borderColor: 'var(--border-subtle)' }}
                  >
                    <span
                      className="flex items-center gap-1.5 text-sm font-bold"
                      style={{ color: 'var(--text-primary)' }}
                    >
                      {occurrence.recurring && <Repeat size={13} aria-label="تکرارشونده" />}
                      {occurrence.title}
                    </span>
                    <span className="text-small" style={{ color: 'var(--text-tertiary)' }}>
                      یادآوری: {occurrence.remindDateLabel} ساعتِ {faTime(occurrence.remindTime)}
                      {occurrence.past ? ' — گذشته' : ''}
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          )}
          {canCreate ? (
            <button
              type="button"
              onClick={() => setPanel({ mode: 'create' })}
              className="flex items-center justify-center gap-1.5 rounded-xl px-4 py-2.5 text-sm font-bold text-white"
              style={{ backgroundColor: 'var(--color-brand-600)' }}
            >
              <Plus size={16} />
              یادآورِ تازه برای این روز
            </button>
          ) : (
            <p className="text-small" style={{ color: 'var(--text-tertiary)' }}>
              روزِ گذشته است؛ یادآورِ تازه فقط برای امروز و روزهای آینده ساخته می‌شود.
            </p>
          )}
        </div>
      )}
    </Card>
  )
}

function ReminderView({
  occurrence,
  editing,
  onEdit,
  onClose,
  onChanged,
}: {
  occurrence: ReminderOccurrence
  editing: boolean
  onEdit: () => void
  onClose: () => void
  onChanged: () => void
}) {
  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.reminders.detail(occurrence.reminderId),
    queryFn: ({ signal }) =>
      api<{ reminder: ReminderDetail }>(`/reminders/${occurrence.reminderId}`, { signal }),
  })

  if (isLoading) return <CardListSkeleton items={1} />
  if (error) return <ErrorState error={error} onRetry={() => void refetch()} />
  if (!data) return null
  const reminder = data.reminder

  if (editing) {
    return (
      <ReminderForm
        targetIso={occurrence.date}
        editing={{ reminder, occurrence: occurrence.date }}
        onCancel={onClose}
        onDone={() => {
          toastSuccess('یادآور به‌روزرسانی شد.')
          onChanged()
        }}
      />
    )
  }

  async function remove(scope: ReminderScope) {
    const ok = await confirmAction({
      title: 'حذفِ یادآور',
      text:
        scope === 'this'
          ? 'آیا مطمئن هستید که می‌خواهید فقط همین یادآور حذف شود؟'
          : scope === 'following'
            ? 'آیا مطمئن هستید که می‌خواهید این یادآور و همه‌ی تکرارهای بعدی‌اش حذف شوند؟'
            : 'آیا مطمئن هستید که می‌خواهید این یادآور حذف شود؟',
      confirmLabel: 'حذف',
      cancelLabel: 'لغو',
      danger: true,
    })
    if (!ok) return
    try {
      const query = reminder.repeatEveryDays ? `?scope=${scope}&occurrence=${occurrence.date}` : ''
      await api(`/reminders/${reminder.id}${query}`, { method: 'DELETE' })
      toastSuccess('یادآور حذف شد.')
      onChanged()
    } catch (err) {
      alertError(err, 'حذفِ یادآور ممکن نشد.')
    }
  }

  const rows: [string, string][] = [
    ['تاریخِ رویداد', occurrence.dateLabel],
    ['تاریخِ یادآوری', `${occurrence.remindDateLabel} ساعتِ ${faTime(occurrence.remindTime)}`],
    ['تکرار', reminder.repeatLabel],
    ['وضعیت', reminder.statusLabel],
  ]
  if (reminder.description) rows.unshift(['توضیحات', reminder.description])

  return (
    <div className="flex flex-col gap-3">
      <h3
        className="flex items-center gap-2 text-lg font-extrabold"
        style={{ color: 'var(--text-primary)' }}
      >
        <BellRing size={17} style={{ color: 'var(--color-brand-600)' }} />
        {reminder.title}
      </h3>
      <dl className="flex flex-col gap-2 text-body">
        {rows.map(([label, value]) => (
          <div key={label} className="flex flex-wrap justify-between gap-2">
            <dt style={{ color: 'var(--text-tertiary)' }}>{label}</dt>
            <dd className="font-bold" style={{ color: 'var(--text-primary)' }}>
              {value}
            </dd>
          </div>
        ))}
      </dl>
      <div className="flex flex-wrap gap-2">
        {occurrence.date >= isoOf(todayInTehran().jy, todayInTehran().jm, todayInTehran().jd) && (
          <button
            type="button"
            onClick={onEdit}
            className="rounded-xl px-4 py-2 text-body font-bold text-white"
            style={{ backgroundColor: 'var(--color-brand-600)' }}
          >
            ویرایش
          </button>
        )}
        {reminder.repeatEveryDays ? (
          <>
            <DangerButton label="حذفِ همین" onClick={() => void remove('this')} />
            <DangerButton label="حذفِ همین و بعدی‌ها" onClick={() => void remove('following')} />
            <DangerButton label="حذفِ همه‌ی تکرارها" onClick={() => void remove('all')} />
          </>
        ) : (
          <DangerButton label="حذف" onClick={() => void remove('all')} />
        )}
        <button
          type="button"
          onClick={onClose}
          className="rounded-xl px-4 py-2 text-body font-semibold"
          style={{ color: 'var(--text-tertiary)' }}
        >
          بازگشت
        </button>
      </div>
    </div>
  )
}

function DangerButton({ label, onClick }: { label: string; onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="flex items-center gap-1 rounded-xl border px-3 py-2 text-body font-semibold"
      style={{ borderColor: 'var(--border-default)', color: 'var(--color-danger)' }}
    >
      <Trash2 size={13} />
      {label}
    </button>
  )
}

/* ─── تاریخچه (بندهای ۸۸ تا ۹۱) ───────────────────────────────────────── */

function ReminderHistory() {
  const client = useQueryClient()
  const [page, setPage] = useState(1)
  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.reminders.history(page),
    queryFn: ({ signal }) =>
      api<ReminderHistoryResponse>(`/reminders/history?page=${page}`, { signal }),
  })
  const refresh = () => void client.invalidateQueries({ queryKey: queryKeys.reminders.all() })

  async function removeOne(reminder: ReminderDetail) {
    const ok = await confirmAction({
      title: 'حذفِ یادآور',
      text: `آیا مطمئن هستید که می‌خواهید «${reminder.title}» از تاریخچه حذف شود؟`,
      confirmLabel: 'حذف',
      cancelLabel: 'لغو',
      danger: true,
    })
    if (!ok) return
    try {
      await api(`/reminders/${reminder.id}`, { method: 'DELETE' })
      toastSuccess('یادآور حذف شد.')
      refresh()
    } catch (err) {
      alertError(err, 'حذفِ یادآور ممکن نشد.')
    }
  }

  async function removeAll() {
    const ok = await confirmAction({
      title: 'حذفِ همه‌ی یادآورها',
      text: 'آیا مطمئن هستید که می‌خواهید تمام یادآورهای خود را حذف کنید؟',
      confirmLabel: 'حذفِ همه',
      cancelLabel: 'لغو',
      danger: true,
    })
    if (!ok) return
    try {
      await api('/reminders', { method: 'DELETE' })
      toastSuccess('همه‌ی یادآورها حذف شدند.')
      setPage(1)
      refresh()
    } catch (err) {
      alertError(err, 'حذفِ یادآورها ممکن نشد.')
    }
  }

  if (isLoading) return <CardListSkeleton items={4} />
  if (error) return <ErrorState error={error} onRetry={() => void refetch()} />
  if (!data) return null
  if (data.data.length === 0) {
    return (
      <EmptyState
        message="هنوز یادآوری ندارید"
        hint="از تقویم، روی یک روز بزنید و یادآورِ تازه بسازید."
      />
    )
  }

  return (
    <div className="flex flex-col gap-3">
      <div className="flex justify-end">
        <DangerButton label="حذفِ همه" onClick={() => void removeAll()} />
      </div>
      <ul className="flex flex-col gap-3">
        {data.data.map((reminder) => (
          <li
            key={reminder.id}
            className="flex flex-wrap items-start justify-between gap-3 rounded-2xl border p-4 shadow-sm"
            style={{
              borderColor: 'var(--border-default)',
              backgroundColor: 'var(--surface-raised)',
            }}
          >
            <div className="flex min-w-0 flex-col gap-1">
              <p className="text-sm font-extrabold" style={{ color: 'var(--text-primary)' }}>
                {reminder.title}
              </p>
              {reminder.description && (
                <p className="text-body" style={{ color: 'var(--text-secondary)' }}>
                  {reminder.description}
                </p>
              )}
              <p className="text-small" style={{ color: 'var(--text-tertiary)' }}>
                رویداد: {reminder.targetDateLabel} · یادآوری: {reminder.remindDateLabel} ساعتِ{' '}
                {faTime(reminder.remindTime)}
              </p>
              <p className="text-small" style={{ color: 'var(--text-tertiary)' }}>
                {reminder.repeatLabel} · ساخته‌شده در {reminder.createdAt}
              </p>
            </div>
            <div className="flex items-center gap-2">
              <span
                className="rounded-full px-2.5 py-1 text-tiny font-bold"
                style={{
                  color:
                    reminder.status === 'scheduled' ? 'var(--state-info)' : 'var(--text-tertiary)',
                  backgroundColor: 'var(--surface-sunken)',
                }}
              >
                {reminder.statusLabel}
              </span>
              <button
                type="button"
                onClick={() => void removeOne(reminder)}
                aria-label={`حذفِ «${reminder.title}»`}
                className="rounded-lg border p-2"
                style={{ borderColor: 'var(--border-subtle)', color: 'var(--color-danger)' }}
              >
                <Trash2 size={14} />
              </button>
            </div>
          </li>
        ))}
      </ul>
      <Pager meta={data.meta} onChange={setPage} noun="یادآور" />
    </div>
  )
}
