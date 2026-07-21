import { motion } from 'framer-motion'
import { Database, Download, Loader2 } from 'lucide-react'
import { Card } from '@/components/ui/Card'
import { EmptyState } from '@/components/ui/PageState'
import { formatNumber } from '@/lib/format'

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
              <div>
                <p className="text-[13px] font-semibold" style={{ color: 'var(--text-primary)' }}>
                  {backup.note ?? 'بکاپ'}
                </p>
                <p className="mt-0.5 text-[11px] tabular-nums" style={{ color: 'var(--text-tertiary)' }}>
                  {backup.createdAt} · {formatNumber(backup.sizeKb)} کیلوبایت
                </p>
              </div>

              <a
                href={backup.downloadUrl}
                className="flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium"
                style={{ borderColor: 'var(--border-default)', color: 'var(--text-secondary)' }}
              >
                <Download size={13} />
                دانلود
              </a>
            </motion.li>
          ))}
        </ul>
      )}
    </Card>
  )
}
