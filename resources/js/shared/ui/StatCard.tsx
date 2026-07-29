import { motion } from 'framer-motion'
import type { LucideIcon } from 'lucide-react'

export type StatTone = 'brand' | 'success' | 'danger' | 'warning' | 'info'

interface StatCardProps {
  label: string
  value: string
  unit?: string
  icon: LucideIcon
  tone?: StatTone
  delay?: number
}

/** رنگ هر تُن از همان متغیرهای پالت خوانده می‌شود تا با صفحه‌ی اصلی یکدست بماند. */
const TONE_COLOR: Record<StatTone, string> = {
  brand: 'var(--color-brand-500)',
  success: 'var(--state-success)',
  danger: 'var(--color-danger)',
  warning: 'var(--color-accent-500)',
  info: 'var(--state-info)',
}

export function StatCard({ label, value, unit, icon: Icon, tone = 'brand', delay = 0 }: StatCardProps) {
  const color = TONE_COLOR[tone]

  return (
    <motion.div
      initial={{ opacity: 0, y: 14 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.35, delay, ease: [0.22, 1, 0.36, 1] }}
      whileHover={{ y: -3 }}
      className="rounded-2xl border p-5"
      style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
    >
      <div className="flex items-start justify-between gap-3">
        <p className="text-[13px]" style={{ color: 'var(--text-secondary)' }}>
          {label}
        </p>
        <span
          className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl"
          style={{ backgroundColor: `color-mix(in srgb, ${color} 14%, transparent)`, color }}
        >
          <Icon size={17} />
        </span>
      </div>

      <p className="mt-3 text-2xl font-extrabold tabular-nums" style={{ color }}>
        {value}
        {unit && (
          <span className="mr-1 text-xs font-normal" style={{ color: 'var(--text-tertiary)' }}>
            {unit}
          </span>
        )}
      </p>
    </motion.div>
  )
}
