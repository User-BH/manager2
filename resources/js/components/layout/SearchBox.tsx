import { useNavigate } from 'react-router-dom'
import { motion, AnimatePresence } from 'framer-motion'
import { Loader2, Search, X } from 'lucide-react'
import { useSearch } from '@/context/SearchContext'
import { formatNumber } from '@/lib/format'

/**
 * باکس جستجوی هدر.
 *
 * تایپ کردن با debounce جستجو را واقعاً اجرا می‌کند، ولی نتیجه عمداً زیر
 * باکس نشان داده نمی‌شود: فقط تعداد یافته‌ها روی دکمه‌ی ذره‌بین می‌نشیند و
 * کلیک روی همان دکمه (یا Enter) به صفحه‌ی «نتایج جستجو» می‌برد. چون داده تا
 * آن لحظه آمده، صفحه‌ی نتایج بدون انتظار دوباره رندر می‌شود.
 */
export function SearchBox() {
  const navigate = useNavigate()
  const { query, setQuery, results, isSearching, rememberSearch } = useSearch()

  const term = query.trim()
  const canSubmit = term.length >= 2
  // شمارنده فقط وقتی معتبر است که پاسخ برای همین عبارت آمده باشد
  const total = results?.query === term ? results.total : null

  function submit() {
    if (!canSubmit) return

    rememberSearch(term, total ?? 0)
    navigate(`/search?q=${encodeURIComponent(term)}`)
  }

  return (
    <form
      role="search"
      onSubmit={(event) => {
        event.preventDefault()
        submit()
      }}
      className="relative hidden w-full max-w-sm sm:block"
    >
      <button
        type="submit"
        disabled={!canSubmit}
        aria-label="نمایش نتایج جستجو"
        title={canSubmit ? 'نمایش نتایج جستجو' : 'برای جستجو حداقل دو نویسه وارد کنید'}
        className="absolute right-1.5 top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-lg transition-colors enabled:hover:bg-(--surface-base) disabled:cursor-default"
        style={{ color: canSubmit ? 'var(--color-brand-500)' : 'var(--text-tertiary)' }}
      >
        {isSearching ? <Loader2 size={16} className="animate-spin" /> : <Search size={16} />}
      </button>

      <input
        type="search"
        value={query}
        onChange={(event) => setQuery(event.target.value)}
        placeholder="جستجو در واحدها، ساکنین، قبوض..."
        className="w-full rounded-xl border py-2 pr-10 pl-24 text-[13.5px] outline-none transition-all duration-200 focus:ring-2"
        style={{
          backgroundColor: 'var(--surface-sunken)',
          borderColor: 'var(--border-subtle)',
          color: 'var(--text-primary)',
          ['--tw-ring-color' as string]: 'var(--ring-focus)',
        }}
      />

      <div className="absolute left-2 top-1/2 flex -translate-y-1/2 items-center gap-1">
        {/* فقط تعداد؛ خودِ نتایج اینجا باز نمی‌شوند */}
        <AnimatePresence>
          {total !== null && !isSearching && (
            <motion.span
              initial={{ opacity: 0, scale: 0.8 }}
              animate={{ opacity: 1, scale: 1 }}
              exit={{ opacity: 0, scale: 0.8 }}
              transition={{ duration: 0.15 }}
              className="whitespace-nowrap rounded-full px-1.5 py-0.5 text-[10px] font-bold tabular-nums"
              style={{
                backgroundColor:
                  total > 0
                    ? 'color-mix(in srgb, var(--color-brand-500) 15%, transparent)'
                    : 'var(--surface-base)',
                color: total > 0 ? 'var(--color-brand-600)' : 'var(--text-tertiary)',
              }}
            >
              {total > 0 ? `${formatNumber(total)} نتیجه` : 'بدون نتیجه'}
            </motion.span>
          )}
        </AnimatePresence>

        {query && (
          <button
            type="button"
            onClick={() => setQuery('')}
            className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full transition-colors hover:bg-(--border-subtle)"
            style={{ color: 'var(--text-tertiary)' }}
            aria-label="پاک کردن جستجو"
          >
            <X size={13} />
          </button>
        )}
      </div>
    </form>
  )
}
