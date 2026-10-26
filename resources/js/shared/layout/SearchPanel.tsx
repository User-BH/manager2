import { useEffect, useRef, type RefObject } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { motion } from 'framer-motion'
import { ChevronLeft, History, Search, X } from 'lucide-react'
import { useSearch } from '@/shared/stores/searchStore'
import { formatNumber } from '@/shared/lib/format'
import { SEARCH_PANEL_ID } from './TopBarActions'

/** چند جست‌وجوی آخر در پنل؛ مدیریتِ همه در صفحه‌ی «جستجوهای اخیر» در محتوای اصلی (ت-۱۱) */
const RECENT_IN_PANEL = 5

/**
 * پنلِ جست‌وجوی عرضِ کم — زیرِ سربرگ، نه پنجره‌ی وسطِ صفحه (مرحلهٔ ۱۳ · فاز ۴؛ ایرادات ۱۰، بندهای ۵۴ تا ۶۳).
 *
 *   سربرگ ← این پنل ← محتوای اصلی
 *
 * ورودی، دکمه‌ی «جستجو» و چند جست‌وجوی آخر؛ نتیجه در محتوای اصلی (`/search`)، نه این‌جا. بستن: ×، دوباره‌کلیکِ آیکون،
 * کلیکِ بیرون، Escape و رفتن به صفحه‌ی دیگر. همان `searchStore` ِ کادرِ دسکتاپ — یک تاریخچه، یک قاعده‌ی دو نویسه.
 *
 * تنبل است: تا کاربر آیکون را نزند بار نمی‌شود (بودجه‌ی نشست).
 *
 * لایه: درونِ سربرگ (`z-45`، stacking context ِ خودش) — بالای محتوا، کارت و جدول، زیرِ Modal و کشو (`z-50`).
 */
export function SearchPanel({
  onClose,
  toggleRef,
}: {
  onClose: (returnFocus: boolean) => void
  toggleRef: RefObject<HTMLButtonElement | null>
}) {
  const navigate = useNavigate()
  const panelRef = useRef<HTMLDivElement>(null)
  const { query, setQuery, results, rememberSearch, recent } = useSearch()
  const term = query.trim()
  const canSubmit = term.length >= 2

  useEffect(() => {
    // کلیکِ بیرون (جز خودِ آیکون، که خودش باز و بسته می‌کند) و Escape
    function onPointer(event: PointerEvent) {
      const target = event.target as Node
      if (panelRef.current?.contains(target) || toggleRef.current?.contains(target)) return
      onClose(false)
    }
    function onKey(event: KeyboardEvent) {
      if (event.key === 'Escape') onClose(true)
    }

    document.addEventListener('pointerdown', onPointer)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('pointerdown', onPointer)
      document.removeEventListener('keydown', onKey)
    }
  }, [onClose, toggleRef])

  function open(value: string, total: number) {
    rememberSearch(value, total)
    void navigate(`/search?q=${encodeURIComponent(value)}`)
  }

  return (
    <motion.div
      ref={panelRef}
      id={SEARCH_PANEL_ID}
      role="region"
      aria-label="جستجو"
      initial={{ opacity: 0, y: -8 }}
      animate={{ opacity: 1, y: 0 }}
      exit={{ opacity: 0, y: -8 }}
      transition={{ duration: 0.18, ease: [0.22, 1, 0.36, 1] }}
      className="absolute inset-x-0 top-full border-b px-4 pt-3 pb-4 shadow-lg sm:hidden"
      style={{ backgroundColor: 'var(--surface-raised)', borderColor: 'var(--border-subtle)' }}
    >
      <form
        role="search"
        className="flex items-center gap-2"
        onSubmit={(event) => {
          event.preventDefault()
          if (canSubmit) open(term, results?.query === term ? results.total : 0)
        }}
      >
        <input
          type="search"
          // پنل با کلیکِ خودِ کاربر باز می‌شود؛ فوکوسِ ورودی همان انتظارِ اوست
          // eslint-disable-next-line jsx-a11y/no-autofocus
          autoFocus
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          // کوتاه: در ۳۲۰ تا ۴۰۰px کنارِ دو دکمه جا می‌شود و بریده نمی‌شود (بندِ ۶۳)؛ نامِ کامل در `aria-label`
          placeholder="واحد، ساکن یا قبض…"
          aria-label="جستجو در واحدها، ساکنین و قبوض"
          enterKeyHint="search"
          // ۱۶px: سافاریِ iOS ورودیِ ریزتر را با بزرگ‌نمایی باز می‌کند
          className="field min-w-0 flex-1 rounded-xl px-3.5 py-2.5 text-base"
        />
        <button
          type="submit"
          disabled={!canSubmit}
          title={canSubmit ? undefined : 'برای جستجو حداقل دو نویسه وارد کنید'}
          className="flex h-11 shrink-0 items-center gap-1.5 rounded-xl px-4 text-sm font-bold text-white transition-opacity disabled:opacity-50"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          <Search size={16} aria-hidden="true" />
          جستجو
        </button>
        <button
          type="button"
          onClick={() => onClose(true)}
          aria-label="بستن جستجو"
          className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl transition-colors hover:bg-(--surface-sunken)"
          style={{ color: 'var(--text-tertiary)' }}
        >
          <X size={18} aria-hidden="true" />
        </button>
      </form>

      <div className="mt-3">
        <p
          className="mb-1.5 flex items-center gap-1.5 text-xs font-semibold"
          style={{ color: 'var(--text-tertiary)' }}
        >
          <History size={13} aria-hidden="true" />
          آخرین جستجوها
        </p>
        {recent.length === 0 ? (
          <p className="text-small" style={{ color: 'var(--text-tertiary)' }}>
            هنوز جستجویی نکرده‌اید.
          </p>
        ) : (
          <ul className="flex flex-col" aria-label="آخرین جستجوها">
            {recent.slice(0, RECENT_IN_PANEL).map((item) => (
              <li key={item.query}>
                <button
                  type="button"
                  onClick={() => {
                    setQuery(item.query)
                    open(item.query, item.total)
                  }}
                  className="flex min-h-11 w-full items-center gap-2 rounded-lg px-2 text-right text-sm transition-colors hover:bg-(--surface-sunken)"
                  style={{ color: 'var(--text-secondary)' }}
                >
                  <span
                    className="min-w-0 flex-1 truncate"
                    style={{ color: 'var(--text-primary)' }}
                  >
                    {item.query}
                  </span>
                  <span
                    className="shrink-0 text-xs tabular-nums"
                    style={{ color: 'var(--text-tertiary)' }}
                  >
                    {item.total > 0 ? `${formatNumber(item.total)} نتیجه` : 'بدون نتیجه'}
                  </span>
                </button>
              </li>
            ))}
          </ul>
        )}
        <Link
          to="/search"
          className="mt-2 inline-flex items-center gap-1 text-xs font-bold"
          style={{ color: 'var(--color-brand-600)' }}
        >
          مدیریتِ جستجوهای اخیر
          <ChevronLeft size={13} aria-hidden="true" />
        </Link>
      </div>
    </motion.div>
  )
}
