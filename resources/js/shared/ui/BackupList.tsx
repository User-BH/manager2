import { motion } from 'framer-motion'
import { AlertTriangle, Database, Download, Loader2 } from 'lucide-react'
import { Card } from '@/shared/ui/Card'
import { EmptyState } from '@/shared/ui/PageState'
import { formatNumber } from '@/shared/lib/format'

export interface BackupRow {
  id: number
  type: string
  status: string
  note: string | null
  sizeKb: number
  createdAt: string
  downloadUrl: string
}

interface BackupListProps {
  backups: BackupRow[]
  busy: boolean
  onCreate: () => void
  createLabel: string
  emptyMessage: string
  delay?: number
}

/**
 * وضعیتِ بکاپی که هنوز فایلش آماده نیست.
 *
 * `pending` عمداً اسپینر دارد و نه فقط متن: کاربر باید بفهمد کاری در جریان
 * است و صفحه خودش به‌روز می‌شود، وگرنه دکمه را دوباره می‌زند و بکاپِ تکراری
 * می‌سازد.
 */
function StatusBadge({ status }: { status: string }) {
  const pending = status === 'pending'

  return (
    <span
      className="flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium"
      style={{
        backgroundColor: `color-mix(in srgb, ${
          pending ? 'var(--color-accent-500)' : 'var(--color-danger)'
        } 12%, transparent)`,
        color: pending ? 'var(--color-accent-600)' : 'var(--color-danger)',
      }}
    >
      {pending ? (
        <>
          <Loader2 size={13} className="animate-spin" />
          در حال ساخت…
        </>
      ) : (
        <>
          <AlertTriangle size={13} />
          ناموفق
        </>
      )}
    </span>
  )
}

/** فهرست بکاپ‌ها — بین بکاپ مجتمع و بکاپ کل سیستم مشترک است. */
export function BackupList({
  backups,
  busy,
  onCreate,
  createLabel,
  emptyMessage,
  delay = 0,
}: BackupListProps) {
  return (
    <Card
      title="بکاپ‌های گرفته‌شده"
      delay={delay}
      actions={
        <button
          onClick={onCreate}
          disabled={busy}
          className="flex items-center gap-1.5 rounded-xl px-4 py-2 text-[13px] font-bold text-white disabled:opacity-60"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          {busy ? <Loader2 size={15} className="animate-spin" /> : <Database size={15} />}
          {createLabel}
        </button>
      }
    >
      {backups.length === 0 ? (
        <EmptyState message={emptyMessage} />
      ) : (
        <ul className="flex flex-col gap-2">
          {backups.map((backup, index) => (
            <motion.li
              key={backup.id}
              initial={{ opacity: 0, y: 8 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.25, delay: Math.min(index * 0.03, 0.25) }}
              className="flex items-center justify-between rounded-xl px-4 py-3"
              style={{ backgroundColor: 'var(--surface-sunken)' }}
            >
              <div className="min-w-0">
                <p className="text-[13px] font-semibold" style={{ color: 'var(--text-primary)' }}>
                  {backup.note ?? 'بکاپ'}
                </p>
                <p
                  className="mt-0.5 text-[11px] tabular-nums"
                  style={{ color: 'var(--text-tertiary)' }}
                >
                  {/*
                    تا وقتی فایل ساخته نشده، حجم صفر است و نشان‌دادنش
                    («۰ کیلوبایت») گمراه‌کننده است — انگار بکاپ خالی گرفته شده.
                  */}
                  {backup.createdAt}
                  {backup.status === 'completed' && ` · ${formatNumber(backup.sizeKb)} کیلوبایت`}
                </p>
              </div>

              {/*
                ساختِ بکاپ از R11 در صف انجام می‌شود، پس ردیف پیش از آماده‌شدنِ
                فایل دیده می‌شود. لینکِ دانلود فقط وقتی معنا دارد که فایل باشد؛
                در بقیه‌ی حالت‌ها وضعیت نشان داده می‌شود تا کاربر بداند منتظر
                بماند یا دوباره تلاش کند.
              */}
              {backup.status === 'completed' ? (
                <a
                  href={backup.downloadUrl}
                  className="flex shrink-0 items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium"
                  style={{ borderColor: 'var(--border-default)', color: 'var(--text-secondary)' }}
                >
                  <Download size={13} />
                  دانلود
                </a>
              ) : (
                <StatusBadge status={backup.status} />
              )}
            </motion.li>
          ))}
        </ul>
      )}
    </Card>
  )
}
