import { useNavigate } from 'react-router-dom'
import { motion } from 'framer-motion'
import { ShieldAlert, ArrowRight, Home } from 'lucide-react'
import { Logo } from '@/components/common/Logo'
import { useDocumentTitle } from '@/hooks'

export function ForbiddenPage() {
  const navigate = useNavigate()

  useDocumentTitle('دسترسی غیرمجاز')

  return (
    <div
      className="flex min-h-screen flex-col items-center justify-center px-6 text-center"
      style={{ backgroundColor: 'var(--surface-canvas)' }}
      dir="rtl"
    >
      <motion.div
        initial={{ opacity: 0, y: -16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.5 }}
        className="mb-10"
      >
        <Logo size={36} />
      </motion.div>

      <motion.div
        initial={{ opacity: 0, scale: 0.8 }}
        animate={{ opacity: 1, scale: 1 }}
        transition={{ type: 'spring', stiffness: 200, damping: 16, delay: 0.1 }}
        className="relative flex h-28 w-28 items-center justify-center rounded-full"
        style={{ backgroundColor: 'color-mix(in srgb, var(--color-danger) 12%, transparent)' }}
      >
        <motion.div
          animate={{ scale: [1, 1.06, 1] }}
          transition={{ duration: 2.4, repeat: Infinity, ease: 'easeInOut' }}
        >
          <ShieldAlert size={48} style={{ color: 'var(--color-danger)' }} />
        </motion.div>
      </motion.div>

      <motion.h1
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.5, delay: 0.2 }}
        className="mt-8 text-5xl font-extrabold tracking-tight"
        style={{ color: 'var(--text-primary)' }}
      >
        ۴۰۳
      </motion.h1>

      <motion.p
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.5, delay: 0.28 }}
        className="mt-3 max-w-sm text-[15px] font-semibold"
        style={{ color: 'var(--text-primary)' }}
      >
        دسترسی به این صفحه امکان‌پذیر نیست
      </motion.p>

      <motion.p
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.5, delay: 0.34 }}
        className="mt-2 max-w-sm text-sm leading-7"
        style={{ color: 'var(--text-tertiary)' }}
      >
        مسیری که وارد کرده‌اید وجود ندارد یا اجازه‌ی دسترسی به آن را ندارید.
      </motion.p>

      <motion.div
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.5, delay: 0.42 }}
        className="mt-9 flex flex-wrap items-center justify-center gap-3"
      >
        <button
          onClick={() => navigate(-1)}
          className="flex items-center gap-2 rounded-xl border px-5 py-3 text-sm font-semibold transition-colors hover:bg-(--surface-sunken)"
          style={{ borderColor: 'var(--border-default)', color: 'var(--text-primary)' }}
        >
          <ArrowRight size={16} />
          بازگشت به صفحه قبل
        </button>
        <button
          onClick={() => navigate('/')}
          className="flex items-center gap-2 rounded-xl px-5 py-3 text-sm font-semibold text-white shadow-sm transition-transform duration-200 hover:scale-105"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          <Home size={16} />
          صفحه اصلی
        </button>
      </motion.div>
    </div>
  )
}
