import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import { api, ApiError } from '@/lib/api'
import { useDebounce, useLocalStorage } from '@/hooks'
import type { SearchResponse, RecentSearch } from '@/types'

interface SearchContextValue {
  /** آنچه کاربر همین حالا تایپ کرده است. */
  query: string
  setQuery: (value: string) => void
  /** نسخه‌ی تاخیردار همان مقدار؛ درخواست روی این زده می‌شود. */
  debouncedQuery: string
  results: SearchResponse | null
  isSearching: boolean
  error: string | null
  /** آیا نتیجه‌ای برای رفتن به صفحه‌ی نتایج آماده هست. */
  hasResults: boolean
  recent: RecentSearch[]
  rememberSearch: (query: string, total: number) => void
  removeRecent: (query: string) => void
  clearRecent: () => void
}

const SearchContext = createContext<SearchContextValue | undefined>(undefined)

const RECENT_KEY = 'app:recent-searches'
const MAX_RECENT = 8
const MIN_LENGTH = 2

/**
 * حالت جستجوی سراسری.
 *
 * چرا context و نه state داخل SearchBox: نتیجه‌ی جستجو در دو جای متفاوت لازم
 * است — شمارنده‌ی کنار ذره‌بین در هدر، و صفحه‌ی نتایج در وسط صفحه. اگر هرکدام
 * خودش fetch می‌کرد، کلیک روی ذره‌بین یک درخواست دوم می‌زد و کاربر دوباره
 * منتظر می‌ماند، درحالی‌که همان داده لحظه‌ای قبل آمده بود.
 */
export function SearchProvider({ children }: { children: ReactNode }) {
  const [query, setQuery] = useState('')
  const [results, setResults] = useState<SearchResponse | null>(null)
  const [isSearching, setIsSearching] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [recent, setRecent] = useLocalStorage<RecentSearch[]>(RECENT_KEY, [])

  const debouncedQuery = useDebounce(query, 450)

  // آخرین درخواست برنده است: اگر کاربر سریع تایپ کند، پاسخ دیرهنگامِ عبارت
  // قبلی نباید نتیجه‌ی عبارت تازه را بازنویسی کند.
  const requestId = useRef(0)

  useEffect(() => {
    const term = debouncedQuery.trim()

    if (term.length < MIN_LENGTH) {
      setResults(null)
      setError(null)
      setIsSearching(false)
      return
    }

    const id = ++requestId.current
    const controller = new AbortController()

    setIsSearching(true)
    setError(null)

    api<SearchResponse>(`/search?q=${encodeURIComponent(term)}`, { signal: controller.signal })
      .then((response) => {
        if (id !== requestId.current) return
        setResults(response)
        setIsSearching(false)
      })
      .catch((err: unknown) => {
        if (controller.signal.aborted || id !== requestId.current) return
        setError(err instanceof ApiError ? err.message : 'جستجو انجام نشد.')
        setIsSearching(false)
      })

    return () => controller.abort()
  }, [debouncedQuery])

  const rememberSearch = useCallback(
    (value: string, total: number) => {
      const term = value.trim()
      if (term.length < MIN_LENGTH) return

      setRecent((prev) => [
        { query: term, total, at: Date.now() },
        // عبارت تکراری بالا می‌آید به‌جای اینکه دوباره ثبت شود
        ...prev.filter((item) => item.query !== term),
      ].slice(0, MAX_RECENT))
    },
    [setRecent],
  )

  const removeRecent = useCallback(
    (value: string) => setRecent((prev) => prev.filter((item) => item.query !== value)),
    [setRecent],
  )

  const clearRecent = useCallback(() => setRecent([]), [setRecent])

  return (
    <SearchContext.Provider
      value={{
        query,
        setQuery,
        debouncedQuery,
        results,
        isSearching,
        error,
        hasResults: (results?.total ?? 0) > 0,
        recent,
        rememberSearch,
        removeRecent,
        clearRecent,
      }}
    >
      {children}
    </SearchContext.Provider>
  )
}

export function useSearch() {
  const ctx = useContext(SearchContext)
  if (!ctx) throw new Error('useSearch باید داخل SearchProvider استفاده شود')
  return ctx
}
