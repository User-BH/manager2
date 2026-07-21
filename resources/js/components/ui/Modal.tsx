import { useEffect, type ReactNode } from 'react'
import { AnimatePresence, motion } from 'framer-motion'
import { X } from 'lucide-react'

interface ModalProps {
  open: boolean
  title: string
  onClose: () => void
  children: ReactNode
}

export function Modal({ open, title, onClose, children }: ModalProps) {
  // بستن با Escape و جلوگیری از اسکرول پس‌زمینه تا وقتی مودال باز است
  useEffect(() => {
    if (!open) return

    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    document.addEventListener('keydown', onKey)

    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = previousOverflow
    }
  }, [open, onClose])

  return (
    <AnimatePresence>
      {open && (
        <div className="fixed inset-0 z-50 flex items-end justify-center p-0 sm:items-center sm:p-4">
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            onClick={onClose}
            className="absolute inset-0 bg-black/45"
          />

          <motion.div
            role="dialog"
            aria-modal="true"
            aria-label={title}
            initial={{ opacity: 0, y: 24, scale: 0.98 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: 24, scale: 0.98 }}
            transition={{ type: 'spring', stiffness: 340, damping: 30 }}
            className="scrollbar-thin relative max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-t-3xl border sm:rounded-3xl"
            style={{ backgroundColor: 'var(--surface-base)', borderColor: 'var(--border-subtle)' }}
          >
            <header
              className="sticky top-0 z-10 flex items-center justify-between border-b px-5 py-4 backdrop-blur"
              style={{
                borderColor: 'var(--border-subtle)',
                backgroundColor: 'color-mix(in srgb, var(--surface-base) 92%, transparent)',
              }}
            >
              <h2 className="text-[15px] font-bold" style={{ color: 'var(--text-primary)' }}>
                {title}
              </h2>
              <button
                onClick={onClose}
                aria-label="بستن"
                className="flex h-8 w-8 items-center justify-center rounded-lg transition-colors hover:bg-(--surface-sunken)"
                style={{ color: 'var(--text-secondary)' }}
              >
                <X size={17} />
              </button>
            </header>

            <div className="p-5">{children}</div>
          </motion.div>
        </div>
      )}
    </AnimatePresence>
  )
}
