import { useId, useState, type ReactNode } from 'react'
import { AnimatePresence, motion } from 'framer-motion'

/**
 * تولتیپِ سفارشی به‌جای `title` پیش‌فرض مرورگر.
 *
 * تولتیپ بومیِ مرورگر با تأخیر می‌آید، استایل‌پذیر نیست و در تم تاریک بد
 * دیده می‌شود. این نسخه با پالت پروژه هماهنگ است، با هاور و فوکوس (برای
 * دسترس‌پذیری) ظاهر می‌شود و انیمیشن نرم دارد.
 *
 * چون این کامپوننت فقط یک لفافه است، `title` را از فرزندش باید برداشت تا
 * تولتیپ بومی و این هم‌زمان نشان داده نشوند.
 */
export function Tooltip({
  label,
  children,
  side = 'bottom',
}: {
  label: string
  children: ReactNode
  side?: 'top' | 'bottom'
}) {
  const [open, setOpen] = useState(false)
  const id = useId()

  const isBottom = side === 'bottom'

  return (
    <div
      className="relative flex"
      onMouseEnter={() => setOpen(true)}
      onMouseLeave={() => setOpen(false)}
      onFocusCapture={() => setOpen(true)}
      onBlurCapture={() => setOpen(false)}
    >
      <div aria-describedby={open ? id : undefined} className="flex">
        {children}
      </div>

      <AnimatePresence>
        {open && (
          <motion.span
            id={id}
            role="tooltip"
            initial={{ opacity: 0, y: isBottom ? -4 : 4, scale: 0.94 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: isBottom ? -4 : 4, scale: 0.94 }}
            transition={{ duration: 0.15, ease: [0.22, 1, 0.36, 1] }}
            className={
              'pointer-events-none absolute left-1/2 z-50 -translate-x-1/2 whitespace-nowrap rounded-lg px-2.5 py-1 text-[11px] font-semibold shadow-lg ' +
              (isBottom ? 'top-[calc(100%+8px)]' : 'bottom-[calc(100%+8px)]')
            }
            style={{
              backgroundColor: 'var(--surface-overlay)',
              color: 'var(--text-primary)',
              border: '1px solid var(--border-subtle)',
              boxShadow: 'var(--shadow-ambient)',
            }}
          >
            {label}

            {/* فلشِ کوچک که تولتیپ را به آیکون وصل می‌کند */}
            <span
              className="absolute left-1/2 h-2 w-2 -translate-x-1/2 rotate-45"
              style={{
                backgroundColor: 'var(--surface-overlay)',
                borderInlineStart: '1px solid var(--border-subtle)',
                borderBlockStart: '1px solid var(--border-subtle)',
                ...(isBottom
                  ? { top: '-4.5px' }
                  : { bottom: '-4.5px', transform: 'translateX(-50%) rotate(225deg)' }),
              }}
            />
          </motion.span>
        )}
      </AnimatePresence>
    </div>
  )
}
