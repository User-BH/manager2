import { useNavigate } from 'react-router-dom'
import { AnimatePresence, motion } from 'framer-motion'
import { ChevronDown, History, Search, Trash2, X } from 'lucide-react'
import { useSearch } from '@/context/SearchContext'
import { useToggle } from '@/hooks'
import { formatNumber, formatRelative } from '@/lib/format'

/**
 * بخش «جستجوهای اخیر» در سایدبار.
 *
 * تاریخچه در localStorage می‌ماند، نه در سرور: چیزی که کاربر تایپ کرده
 * ممکن است نام و شماره‌ی افراد باشد و لازم نیست روی سرور ذخیره شود.
 * وقتی سایدبار جمع است پنهان می‌شود چون در ۷۶ پیکسل عرض خوانا نیست.
 */
export function SidebarRecentSearches({
  collapsed,
  onNavigate,
}: {
  collapsed: boolean
  onNavigate?: () => void
}) {
  const [open, , setOpen] = useToggle(true)
  const navigate = useNavigate()
  const { recent, removeRecent, clearRecent, setQuery } = useSearch()

  if (collapsed || recent.length === 0) return null

  function openSearch(query: string) {
    // باکس هدر هم پر می‌شود تا کاربر ببیند چه چیزی در حال جستجوست
    setQuery(query)
    navigate(`/search?q=${encodeURIComponent(query)}`)
    onNavigate?.()
  }

  return (
    <div className="mt-5">
      <button
        onClick={() => setOpen((prev) => !prev)}
        className="mb-1.5 flex w-full items-center gap-1.5 px-2.5 text-[11px] font-semibold tracking-wide transition-colors hover:opacity-80"
        style={{ color: 'var(--text-tertiary)' }}
        aria-expanded={open}
      >
        <History size={12} />
        جستجوهای اخیر
        <span className="tabular-nums">({formatNumber(recent.length)})</span>

        <motion.span
          animate={{ rotate: open ? 0 : -90 }}
          transition={{ duration: 0.18 }}
          className="mr-auto flex items-center"
        >
          <ChevronDown size={13} />
        </motion.span>
      </button>

      <AnimatePresence initial={false}>
        {open && (
          <motion.div
            initial={{ height: 0, opacity: 0 }}
            animate={{ height: 'auto', opacity: 1 }}
            exit={{ height: 0, opacity: 0 }}
            transition={{ duration: 0.22, ease: 'easeInOut' }}
            className="overflow-hidden"
          >
            <ul className="flex flex-col gap-0.5">
              {recent.map((item) => (
                <li key={item.query} className="group relative">
                  <button
                    onClick={() => openSearch(item.query)}
                    className="flex w-full items-center gap-2 rounded-xl px-2.5 py-2 text-right transition-colors hover:bg-(--surface-sunken)"
                  >
                    <Search size={14} className="shrink-0" style={{ color: 'var(--text-tertiary)' }} />

                    <span className="min-w-0 flex-1">
                      <span
                        className="block truncate text-[12.5px] font-medium"
                        style={{ color: 'var(--text-secondary)' }}
                      >
                        {item.query}
                      </span>
                      <span className="block text-[10.5px]" style={{ color: 'var(--text-tertiary)' }}>
                        {formatNumber(item.total)} نتیجه · {formatRelative(item.at)}
                      </span>
                    </span>
                  </button>

                  <button
                    onClick={() => removeRecent(item.query)}
                    aria-label={`حذف «${item.query}» از تاریخچه`}
                    className="absolute left-1.5 top-1/2 flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded-lg opacity-0 transition-opacity hover:bg-(--border-subtle) focus-visible:opacity-100 group-hover:opacity-100"
                    style={{ color: 'var(--text-tertiary)' }}
                  >
                    <X size={12} />
                  </button>
                </li>
              ))}
            </ul>

            <button
              onClick={clearRecent}
              className="mt-1 flex w-full items-center gap-1.5 rounded-xl px-2.5 py-1.5 text-[11px] transition-colors hover:bg-(--surface-sunken)"
              style={{ color: 'var(--text-tertiary)' }}
            >
              <Trash2 size={12} />
              پاک کردن تاریخچه
            </button>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  )
}
