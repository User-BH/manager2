import { type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { motion } from 'framer-motion'
import { ArrowRight } from 'lucide-react'
import { LogoMark } from '@/components/common/LogoMark'
import { ThemeToggle } from '@/components/layout/ThemeToggle'
import { BRAND_NAME } from '@/config/brand'

/**
 * پوسته‌ی مینیمالِ صفحه‌های احراز هویتِ تک‌کاره (تایید کد، فراموشی رمز).
 *
 * برخلاف صفحه‌ی دوپنلیِ ورود، اینجا فقط یک کارتِ کوچک وسط صفحه است روی یک
 * پس‌زمینه‌ی گرادیانیِ زنده با حباب‌های نرم؛ ساده ولی دلپذیر.
 */
export function AuthScreen({
  title,
  subtitle,
  children,
  backTo = '/auth',
  backLabel = 'بازگشت به ورود',
}: {
  title: string
  subtitle?: string
  children: ReactNode
  backTo?: string
  backLabel?: string
}) {
  return (
    <div
      className="relative flex min-h-screen items-center justify-center overflow-hidden px-4 py-10"
      style={{ backgroundColor: 'var(--surface-canvas)' }}
      dir="rtl"
    >
      {/* پس‌زمینه‌ی گرادیانی با حباب‌های شناور */}
      <div
        className="pointer-events-none absolute inset-0"
        style={{
          background:
            'radial-gradient(60rem 40rem at 80% -10%, color-mix(in srgb, var(--color-brand-500) 22%, transparent), transparent), radial-gradient(50rem 40rem at 0% 110%, color-mix(in srgb, var(--color-brand-400) 20%, transparent), transparent)',
        }}
      />
      {[0, 1, 2].map((i) => (
        <motion.div
          key={i}
          className="pointer-events-none absolute rounded-full"
          style={{
            width: 180 + i * 90,
            height: 180 + i * 90,
            left: `${[8, 70, 40][i]}%`,
            top: `${[20, 55, 12][i]}%`,
            background: 'radial-gradient(circle, color-mix(in srgb, var(--color-brand-500) 12%, transparent), transparent 70%)',
          }}
          animate={{ y: [0, -18, 0], x: [0, 10, 0] }}
          transition={{ duration: 9 + i * 2, repeat: Infinity, ease: 'easeInOut' }}
        />
      ))}

      <div className="absolute right-4 top-4 lg:right-6 lg:top-6">
        <ThemeToggle />
      </div>
      <Link
        to={backTo}
        className="absolute left-4 top-5 flex items-center gap-1.5 text-[13px] font-medium lg:left-6"
        style={{ color: 'var(--text-secondary)' }}
      >
        <ArrowRight size={15} />
        {backLabel}
      </Link>

      <motion.div
        initial={{ opacity: 0, y: 20, scale: 0.98 }}
        animate={{ opacity: 1, y: 0, scale: 1 }}
        transition={{ type: 'spring', stiffness: 260, damping: 24 }}
        className="relative w-full max-w-sm rounded-3xl border p-7 shadow-2xl backdrop-blur"
        style={{
          borderColor: 'var(--border-subtle)',
          backgroundColor: 'color-mix(in srgb, var(--surface-base) 88%, transparent)',
        }}
      >
        <div className="mb-6 flex flex-col items-center text-center">
          <div className="mb-3 flex items-center gap-2">
            <LogoMark size={30} />
            <span className="text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
              {BRAND_NAME}
            </span>
          </div>
          <h1 className="text-lg font-extrabold" style={{ color: 'var(--text-primary)' }}>
            {title}
          </h1>
          {subtitle && (
            <p className="mt-2 text-[13px] leading-6" style={{ color: 'var(--text-tertiary)' }}>
              {subtitle}
            </p>
          )}
        </div>

        {children}
      </motion.div>
    </div>
  )
}
