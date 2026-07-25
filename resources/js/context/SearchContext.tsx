import { create } from 'zustand'
import { api, ApiError } from '@/lib/api'
import type { RecentSearch, SearchResponse } from '@/types'

/**
 * حالتِ جستجوی سراسری با zustand.
 *
 * نتیجه در دو جا لازم است — شمارنده‌ی کنار ذره‌بین در هدر و صفحه‌ی نتایج — پس
 * یک store مشترک است تا کلیک روی ذره‌بین درخواستِ دومی نزند. debounce و
 * «آخرین درخواست برنده است» داخلِ خودِ store مدیریت می‌شوند.
 */

const RECENT_KEY = 'app:recent-searches'
const MAX_RECENT = 8
const MIN_LENGTH = 2
const DEBOUNCE_MS = 450

function readRecent(): RecentSearch[] {
  try {
    const raw = window.localStorage.getItem(RECENT_KEY)
    return raw ? (JSON.parse(raw) as RecentSearch[]) : []
  } catch {
    return []
  }
}

function writeRecent(list: RecentSearch[]): void {
  try {
    window.localStorage.setItem(RECENT_KEY, JSON.stringify(list))
  } catch {
    // فضای ذخیره‌سازی در دسترس نیست؛ بی‌خطر
  }
}

interface SearchState {
  /** آنچه کاربر همین حالا تایپ کرده است. */
  query: string
  /** نسخه‌ی تاخیردار همان مقدار؛ درخواست روی این زده می‌شود. */
  debouncedQuery: string
  results: SearchResponse | null
  isSearching: boolean
  error: string | null
  recent: RecentSearch[]
  setQuery: (value: string) => void
  rememberSearch: (query: string, total: number) => void
  removeRecent: (query: string) => void
  clearRecent: () => void
}

// خارج از state تا در رندرها ثابت بمانند
let debounceTimer: ReturnType<typeof setTimeout> | null = null
let requestId = 0
let controller: AbortController | null = null

export const useSearchStore = create<SearchState>((set, get) => {
  function runSearch(value: string): void {
    const term = value.trim()

    if (term.length < MIN_LENGTH) {
      controller?.abort()
      set({ results: null, error: null, isSearching: false, debouncedQuery: term })
      return
    }

    const id = ++requestId
    controller?.abort()
    controller = new AbortController()

    set({ isSearching: true, error: null, debouncedQuery: term })

    api<SearchResponse>(`/search?q=${encodeURIComponent(term)}`, { signal: controller.signal })
      .then((response) => {
        // فقط پاسخِ آخرین درخواست پذیرفته می‌شود
        if (id !== requestId) return
        set({ results: response, isSearching: false })
      })
      .catch((err: unknown) => {
        if (id !== requestId) return
        // درخواستِ لغوشده خطا نیست
        if (err instanceof ApiError) set({ error: err.message, isSearching: false })
        else set({ error: 'جستجو انجام نشد.', isSearching: false })
      })
  }

  return {
    query: '',
    debouncedQuery: '',
    results: null,
    isSearching: false,
    error: null,
    recent: readRecent(),

    setQuery: (value) => {
      set({ query: value })
      if (debounceTimer) clearTimeout(debounceTimer)
      debounceTimer = setTimeout(() => runSearch(value), DEBOUNCE_MS)
    },

    rememberSearch: (value, total) => {
      const term = value.trim()
      if (term.length < MIN_LENGTH) return
      const next = [
        { query: term, total, at: Date.now() },
        ...get().recent.filter((item) => item.query !== term),
      ].slice(0, MAX_RECENT)
      writeRecent(next)
      set({ recent: next })
    },

    removeRecent: (value) => {
      const next = get().recent.filter((item) => item.query !== value)
      writeRecent(next)
      set({ recent: next })
    },

    clearRecent: () => {
      writeRecent([])
      set({ recent: [] })
    },
  }
})

/** رابطِ سازگار با نسخه‌ی قبلی؛ `hasResults` هم مثل قبل محاسبه می‌شود. */
export function useSearch() {
  const state = useSearchStore()
  return { ...state, hasResults: (state.results?.total ?? 0) > 0 }
}
