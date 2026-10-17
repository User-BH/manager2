import { create } from 'zustand'
import { api, ApiError } from '@/shared/lib/api'
import { useAuthStore } from '@/shared/stores/authStore'
import type { RecentSearch, SearchResponse } from '@/shared/types'
import { forgetRecent, recentKey, readRecent, writeRecent } from '@/shared/lib/recentSearchStorage'

// بررسیِ دادهٔ بیرونیِ تاریخچه — آزمونِ `externalInput` از همین‌جا می‌خواندش
export { isRecent } from '@/shared/lib/recentSearchStorage'

/**
 * حالتِ جستجوی سراسری با zustand.
 *
 * نتیجه در دو جا لازم است — شمارنده‌ی کنار ذره‌بین در هدر و صفحه‌ی نتایج — پس
 * یک store مشترک است تا کلیک روی ذره‌بین درخواستِ دومی نزند. debounce و
 * «آخرین درخواست برنده است» داخلِ خودِ store مدیریت می‌شوند.
 */

const MAX_RECENT = 8
const MIN_LENGTH = 2
const DEBOUNCE_MS = 450

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

/*
 * ورود، خروج یا تعویضِ مجتمع: تاریخچه و نتیجه‌ی جست‌وجوی حساب یا مجتمعِ قبلی همان لحظه کنار می‌رود. تعویضِ
 * مجتمع خودش صفحه را از نو بار می‌کند (`enterNewContext`)؛ این برای ورود و خروجِ درونِ همان صفحه است.
 */
useAuthStore.subscribe((state, previous) => {
  if (recentKey(state.user) === recentKey(previous.user)) return

  /*
   * 🔴 ۱۲/۸ (بندِ ۵۷ ِ سندِ هسته): پایانِ نشست — خروج، بسته‌شدن از دستگاهِ دیگر یا نشستِ منقضی — تاریخچه‌ی همان حساب
   * را (در همه‌ی مجتمع‌هایش) از دستگاه پاک می‌کند؛ همان دلیلِ `clearApiCache` در خروج: نفرِ بعدیِ دستگاهِ مشترک نباید
   * ببیند حسابِ قبلی چه کسی یا چه قبضی را جست‌وجو کرده بود. تا پیش از این می‌ماند و با ورودِ دوباره برمی‌گشت.
   * تعویضِ مجتمع یا حساب بی پایانِ نشست همان کلیدِ جدا را دارد و چیزی پاک نمی‌شود.
   *
   * ⚠️ این‌جا و نه در `authStore`: `authStore` روی مسیرِ همه‌ی صفحه‌هاست و بودجه‌ی نشستِ باندل جا نداشت (سنجیده).
   */
  if (previous.user && !state.user) forgetRecent(previous.user.id)

  // عبارتی که هنوز در debounce است مالِ حسابِ قبلی است؛ نباید با نشستِ تازه برود
  if (debounceTimer) clearTimeout(debounceTimer)
  debounceTimer = null
  controller?.abort()
  useSearchStore.setState({
    recent: readRecent(),
    results: null,
    query: '',
    debouncedQuery: '',
    isSearching: false,
    error: null,
  })
})

/** رابطِ سازگار با نسخه‌ی قبلی؛ `hasResults` هم مثل قبل محاسبه می‌شود. */
export function useSearch() {
  const state = useSearchStore()
  return { ...state, hasResults: (state.results?.total ?? 0) > 0 }
}
