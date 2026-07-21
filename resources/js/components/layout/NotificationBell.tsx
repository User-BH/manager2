import { useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AnimatePresence, motion } from 'framer-motion'
import { Bell, BellOff, CheckCheck, ChevronLeft, Pin } from 'lucide-react'
import { IconButton } from '@/components/ui/IconButton'
import { useNotifications } from '@/context/NotificationContext'
import { useClickOutside } from '@/hooks'
import { formatNumber } from '@/lib/format'
import type { NotificationItem } from '@/types'

/** بعد از بیرون رفتن نشانگر، چقدر صبر شود تا باکس بسته شود. */
const CLOSE_DELAY_MS = 220

/**
 * زنگوله‌ی اعلان‌ها.
 *
 * باز شدن با هاور است، ولی کلیک و صفحه‌کلید هم کار می‌کنند: روی لمسی هاور
 * وجود ندارد و اگر فقط hover بود، دراپ‌داون روی موبایل هرگز باز نمی‌شد.
 * بستنِ تاخیردار هم لازم است وگرنه حرکت نشانگر از دکمه به داخل باکس (که از
 * روی فاصله‌ی بینشان رد می‌شود) باعث بسته شدن ناگهانی می‌شد.
 */
export function NotificationBell() {
  const [open, setOpen] = useState(false)
  const closeTimer = useRef<number | null>(null)
  const wrapperRef = useRef<HTMLDivElement>(null)
  const navigate = useNavigate()

  const { unreadCount, items, markRead, markAllRead } = useNotifications()

  useClickOutside(wrapperRef, () => setOpen(false))

  function cancelClose() {
    if (closeTimer.current !== null) {
      window.clearTimeout(closeTimer.current)
      closeTimer.current = null
    }
  }

  function scheduleClose() {
    cancelClose()
    closeTimer.current = window.setTimeout(() => setOpen(false), CLOSE_DELAY_MS)
  }

  function openItem(item: NotificationItem) {
    if (!item.isRead) void markRead(item.id)

    setOpen(false)
    navigate(`/announcements?focus=${item.id}`)
  }

  return (
    <div
      ref={wrapperRef}
      className="relative"
      onMouseEnter={() => {
        cancelClose()
        setOpen(true)
      }}
      onMouseLeave={scheduleClose}
    >
      <IconButton
        variant="outline"
        aria-label={unreadCount > 0 ? `${unreadCount} اعلان خوانده‌نشده` : 'اعلان‌ها'}
        aria-expanded={open}
        onClick={() => setOpen((prev) => !prev)}
        onFocus={() => setOpen(true)}
        className="relative"
      >
        <Bell size={17} style={{ color: 'var(--text-secondary)' }} />

        {/* شماره‌ی کوچک کنار آیکون؛ با صفر شدن، کلاً محو می‌شود */}
        <AnimatePresence>
          {unreadCount > 0 && (
            <motion.span
              key="badge"
              initial={{ scale: 0, opacity: 0 }}
              animate={{ scale: 1, opacity: 1 }}
              exit={{ scale: 0, opacity: 0 }}
              transition={{ type: 'spring', stiffness: 520, damping: 22 }}
              className="absolute -left-1 -top-1 flex h-[17px] min-w-[17px] items-center justify-center rounded-full px-1 text-[9.5px] font-extrabold tabular-nums text-white ring-2"
              style={{
                backgroundColor: 'var(--color-accent-500)',
                ['--tw-ring-color' as string]: 'var(--surface-base)',
              }}
            >
              {unreadCount > 99 ? '+۹۹' : formatNumber(unreadCount)}
            </motion.span>
          )}
        </AnimatePresence>
      </IconButton>

      <AnimatePresence>
        {open && (
          <motion.div
            initial={{ opacity: 0, y: -8, scale: 0.96 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: -8, scale: 0.96 }}
            transition={{ duration: 0.18, ease: [0.22, 1, 0.36, 1] }}
            className="absolute left-0 top-[calc(100%+10px)] z-40 w-[19rem] origin-top overflow-hidden rounded-2xl border shadow-xl"
            style={{
              backgroundColor: 'var(--surface-raised)',
              borderColor: 'var(--border-subtle)',
              boxShadow: '0 20px 45px -20px color-mix(in srgb, #05100c 55%, transparent)',
            }}
          >
            <div
              className="flex items-center justify-between gap-2 border-b px-3.5 py-2.5"
              style={{ borderColor: 'var(--border-subtle)' }}
            >
              <p className="text-[12.5px] font-bold" style={{ color: 'var(--text-primary)' }}>
                اعلان‌ها
                {unreadCount > 0 && (
                  <span className="mr-1 font-medium" style={{ color: 'var(--text-tertiary)' }}>
                    ({formatNumber(unreadCount)} خوانده‌نشده)
                  </span>
                )}
              </p>

              {unreadCount > 0 && (
                <button
                  onClick={() => void markAllRead()}
                  className="flex items-center gap-1 text-[11px] font-semibold transition-opacity hover:opacity-75"
                  style={{ color: 'var(--color-brand-600)' }}
                >
                  <CheckCheck size={13} />
                  خواندهٔ همه
                </button>
              )}
            </div>

            {items.length === 0 ? (
              <div className="flex flex-col items-center gap-2 px-4 py-8 text-center">
                <BellOff size={22} style={{ color: 'var(--text-tertiary)' }} />
                <p className="text-[12px]" style={{ color: 'var(--text-tertiary)' }}>
                  اعلان تازه‌ای ندارید.
                </p>
              </div>
            ) : (
              <ul>
                {items.map((item, index) => (
                  <motion.li
                    key={item.id}
                    initial={{ opacity: 0, x: 10 }}
                    animate={{ opacity: 1, x: 0 }}
                    transition={{ duration: 0.2, delay: 0.04 + index * 0.05 }}
                  >
                    <button
                      onClick={() => openItem(item)}
                      className="flex w-full gap-2.5 border-b px-3.5 py-3 text-right transition-colors last:border-b-0 hover:bg-(--surface-sunken)"
                      style={{ borderColor: 'var(--border-subtle)' }}
                    >
                      {/* نقطه‌ی نخوانده — جای ثابت می‌گیرد تا ردیف‌ها جابه‌جا نشوند */}
                      <span
                        className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full"
                        style={{
                          backgroundColor: item.isRead
                            ? 'transparent'
                            : 'var(--color-accent-500)',
                        }}
                      />

                      <span className="min-w-0 flex-1">
                        <span className="flex items-center gap-1">
                          {item.isPinned && (
                            <Pin size={11} className="shrink-0" style={{ color: 'var(--color-accent-500)' }} />
                          )}
                          <span
                            className="truncate text-[12.5px]"
                            style={{
                              color: 'var(--text-primary)',
                              fontWeight: item.isRead ? 500 : 800,
                            }}
                          >
                            {item.title}
                          </span>
                        </span>

                        <span
                          className="mt-0.5 block line-clamp-2 text-[11px] leading-6"
                          style={{ color: 'var(--text-tertiary)' }}
                        >
                          {item.excerpt}
                        </span>

                        {item.publishedAt && (
                          <span className="mt-1 block text-[10px]" style={{ color: 'var(--text-tertiary)' }}>
                            {item.publishedAt}
                          </span>
                        )}
                      </span>
                    </button>
                  </motion.li>
                ))}
              </ul>
            )}

            <button
              onClick={() => {
                setOpen(false)
                navigate('/announcements')
              }}
              className="flex w-full items-center justify-center gap-1 py-2.5 text-[12px] font-bold transition-colors hover:bg-(--surface-sunken)"
              style={{
                color: 'var(--color-brand-600)',
                borderTop: '1px solid var(--border-subtle)',
              }}
            >
              نمایش همه اعلان‌ها
              <ChevronLeft size={14} />
            </button>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  )
}
