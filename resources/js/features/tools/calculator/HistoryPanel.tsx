/**
 * تکه‌ای از ماشین‌حساب که از `CalculatorPage.tsx` بیرون کشیده شد (R39 · آیتم ⑤).
 *
 * ⚠️ این جابه‌جایی عمداً **هیچ منطقی را عوض نمی‌کند**: فایلِ اصلی ۹۶۸ خط بود
 * و همه‌ی زیرکامپوننت‌هایش را هم داخلِ خودش داشت. تجزیه از قبل انجام شده
 * بود؛ چیزی که نبود، مرزِ فایلی بود.
 */

import { useMemo } from 'react'
import { motion, AnimatePresence } from 'framer-motion'
import { Delete, History, Search, Trash2 } from 'lucide-react'
import { Card } from '@/shared/ui/Card'
import { useDebounce } from '@/shared/hooks'
import { normalizeDigits } from '@/shared/lib/calculator'
import { formatJalaliDateTime, formatRelative, toPersianDigits } from '@/shared/lib/format'
import type { HistoryEntry } from './types'

export function HistoryPanel({
  history,
  query,
  onQueryChange,
  onReuse,
  onUseResult,
  onRemove,
  onClear,
}: {
  history: HistoryEntry[]
  query: string
  onQueryChange: (value: string) => void
  onReuse: (entry: HistoryEntry) => void
  onUseResult: (entry: HistoryEntry) => void
  onRemove: (id: string) => void
  onClear: () => void
}) {
  // فیلتر با debounce تا تایپ در فهرست بلند کند نشود
  const debounced = useDebounce(query, 250)

  const filtered = useMemo(() => {
    const term = normalizeDigits(debounced.trim().toLowerCase())
    if (!term) return history

    return history.filter(
      (entry) => entry.expression.toLowerCase().includes(term) || entry.result.includes(term),
    )
  }, [history, debounced])

  return (
    <Card delay={0.08} className="flex max-h-[42rem] flex-col">
      <div className="mb-3 flex items-center justify-between gap-2">
        <h2
          className="flex items-center gap-1.5 text-[14px] font-bold"
          style={{ color: 'var(--text-primary)' }}
        >
          <History size={15} style={{ color: 'var(--color-brand-500)' }} />
          تاریخچه
          <span className="text-[11px] font-medium" style={{ color: 'var(--text-tertiary)' }}>
            ({toPersianDigits(history.length)})
          </span>
        </h2>

        {history.length > 0 && (
          <button
            onClick={onClear}
            className="flex items-center gap-1 text-[11px] font-semibold transition-opacity hover:opacity-75"
            style={{ color: 'var(--color-danger)' }}
          >
            <Trash2 size={12} />
            پاک کردن
          </button>
        )}
      </div>

      {history.length > 3 && (
        <div className="relative mb-3">
          <Search
            size={14}
            className="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2"
            style={{ color: 'var(--text-tertiary)' }}
          />
          <input
            value={query}
            onChange={(event) => onQueryChange(event.target.value)}
            placeholder="جستجو در محاسبه‌ها…"
            className="w-full rounded-xl border py-1.5 pr-8 pl-2.5 text-[12px] outline-none focus:ring-2"
            style={{
              backgroundColor: 'var(--surface-sunken)',
              borderColor: 'var(--border-subtle)',
              color: 'var(--text-primary)',
              ['--tw-ring-color' as string]: 'var(--ring-focus)',
            }}
          />
        </div>
      )}

      {filtered.length === 0 ? (
        <div className="flex flex-1 flex-col items-center justify-center gap-2 py-10 text-center">
          <History size={24} style={{ color: 'var(--text-tertiary)' }} />
          <p className="text-[12px]" style={{ color: 'var(--text-tertiary)' }}>
            {history.length === 0 ? 'هنوز محاسبه‌ای ثبت نشده است.' : 'موردی با این عبارت پیدا نشد.'}
          </p>
        </div>
      ) : (
        <ul className="scrollbar-thin -mx-1 flex-1 overflow-y-auto px-1">
          <AnimatePresence initial={false}>
            {filtered.map((entry) => (
              <motion.li
                key={entry.id}
                layout
                initial={{ opacity: 0, y: -6 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0, height: 0 }}
                transition={{ duration: 0.18 }}
                className="group relative border-b last:border-b-0"
                style={{ borderColor: 'var(--border-subtle)' }}
              >
                <button
                  onClick={() => onReuse(entry)}
                  title="بازگرداندن این عبارت به ماشین حساب"
                  className="w-full rounded-lg px-2 py-2.5 pl-16 text-right transition-colors hover:bg-(--surface-sunken)"
                >
                  <span
                    dir="ltr"
                    className="block truncate text-left font-mono text-[12px]"
                    style={{ color: 'var(--text-tertiary)' }}
                  >
                    {entry.expression}
                  </span>

                  <span
                    dir="ltr"
                    className="mt-0.5 block truncate text-left font-mono text-[15px] font-extrabold"
                    style={{ color: 'var(--text-primary)' }}
                  >
                    = {toPersianDigits(entry.result)}
                  </span>

                  <span
                    className="mt-1 flex items-center gap-1.5 text-[10px]"
                    style={{ color: 'var(--text-tertiary)' }}
                    title={formatJalaliDateTime(entry.at)}
                  >
                    <span>{formatJalaliDateTime(entry.at)}</span>
                    <span>·</span>
                    <span>{formatRelative(entry.at)}</span>
                    <span
                      className="rounded px-1 font-bold"
                      style={{ backgroundColor: 'var(--surface-sunken)' }}
                    >
                      {entry.angleMode === 'deg' ? 'DEG' : 'RAD'}
                    </span>
                  </span>
                </button>

                {/*
                  سطل زباله‌ی هر ردیف همیشه دیده می‌شود (نه فقط با هاور)، چون
                  کاربر ممکن است بخواهد فقط چند مورد را پاک کند نه کل تاریخچه.
                  «درج نتیجه» چون کم‌کاربردتر است فقط با هاور ظاهر می‌شود.
                */}
                <div className="absolute left-1.5 top-2.5 flex items-center gap-0.5">
                  <button
                    onClick={() => onUseResult(entry)}
                    aria-label="درج نتیجه در عبارت"
                    title="درج نتیجه در عبارت"
                    className="flex h-7 w-7 items-center justify-center rounded-lg opacity-0 transition-opacity hover:bg-(--border-subtle) focus-visible:opacity-100 group-hover:opacity-100"
                    style={{ color: 'var(--color-brand-600)' }}
                  >
                    <Delete size={13} className="rotate-180" />
                  </button>
                  <button
                    onClick={() => onRemove(entry.id)}
                    aria-label="حذف این مورد از تاریخچه"
                    title="حذف این مورد"
                    className="flex h-7 w-7 items-center justify-center rounded-lg transition-colors hover:bg-(--surface-sunken)"
                    style={{ color: 'var(--color-danger)' }}
                  >
                    <Trash2 size={14} />
                  </button>
                </div>
              </motion.li>
            ))}
          </AnimatePresence>
        </ul>
      )}
    </Card>
  )
}
