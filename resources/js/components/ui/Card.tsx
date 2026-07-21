import type { ReactNode } from 'react'
import { motion } from 'framer-motion'
import { cn } from '@/lib/cn'

interface CardProps {
  title?: string
  subtitle?: string
  actions?: ReactNode
  children: ReactNode
  className?: string
  /** تاخیر ورود انیمیشن، برای اینکه کارت‌ها پشت سر هم ظاهر شوند */
  delay?: number
}

/** کارت پایه‌ی داشبورد — همان سطوح و مرزهای پالت صفحه‌ی اصلی. */
export function Card({ title, subtitle, actions, children, className, delay = 0 }: CardProps) {
  return (
    <motion.section
      initial={{ opacity: 0, y: 12 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.35, delay, ease: [0.22, 1, 0.36, 1] }}
      className={cn('rounded-2xl border p-5', className)}
      style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
    >
      {(title || actions) && (
        <header className="mb-4 flex items-start justify-between gap-3">
          <div>
            {title && (
              <h2 className="text-[15px] font-bold" style={{ color: 'var(--text-primary)' }}>
                {title}
              </h2>
            )}
            {subtitle && (
              <p className="mt-0.5 text-xs" style={{ color: 'var(--text-tertiary)' }}>
                {subtitle}
              </p>
            )}
          </div>
          {actions && <div className="shrink-0">{actions}</div>}
        </header>
      )}

      {children}
    </motion.section>
  )
}
