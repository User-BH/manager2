import { create } from 'zustand'
import { api, ApiError } from '@/shared/lib/api'
import { useAuthStore } from '@/shared/stores/authStore'
import type { CurrentUser, RecentSearch, SearchResponse } from '@/shared/types'

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

/**
 * ⚠️ جست‌وجوهای اخیر از `localStorage` می‌آیند — یعنی دادهٔ **خارجی**.
 *
 * ─── 🔴 چه چیزی باز بود (مرحلهٔ ۵، فاز ۳) ─────────────────────────────────
 * نسخهٔ قبلی `JSON.parse(raw) as RecentSearch[]` بود: یک ادعا، نه یک
 * بررسی. هر چیزی که در آن کلید می‌نشست مستقیم وارد رندر می‌شد.
 *
 * ⚠️ و نکتهٔ گویا این بود که `rememberMe.ts` — درست کنارِ همین فایل —
 * **بررسی می‌کرد**. یعنی الگوی درست در پروژه وجود داشت و اینجا رعایت
 * نشده بود.
 */
export function isRecent(value: unknown): value is RecentSearch[] {
  return (
    Array.isArray(value) &&
    value.every((row) => {
      if (typeof row !== 'object' || row === null) return false

      const item = row as Record<string, unknown>

      return (
        typeof item.query === 'string' &&
        typeof item.total === 'number' &&
        typeof item.at === 'number'
      )
    })
  )
}

/**
 * کلیدِ تاریخچه برای **همین حساب در همین مجتمع** (مرحلهٔ ۱۰ · فاز ۸؛ بندهای ۸۲ و ۸۳ ِ فایلِ معماری).
 *
 * ⚠️ تا ۱۰/۸ یک کلیدِ سراسری بود: «علی احمدی — ۳ نتیجه» که مدیرِ مجتمعِ الف جست‌وجو کرده بود در مجتمعِ ب
 * هم دیده می‌شد، و برای حسابِ بعدی روی همان مرورگر هم. عبارت و شمارِ نتیجه خودشان داده‌ی آن مجتمع‌اند.
 * بی کاربر (پیش از ورود) هیچ چیز خوانده یا نوشته نمی‌شود.
 */
function recentKey(user: CurrentUser | null): string | null {
  return user ? `${RECENT_KEY}:${user.id}:${user.complex?.id ?? 0}` : null
}

function readRecent(): RecentSearch[] {
  const key = recentKey(useAuthStore.getState().user)
  if (!key) return []

  try {
    const raw = window.localStorage.getItem(key)
    if (!raw) return []

    const parsed: unknown = JSON.parse(raw)

    // 🔴 دادهٔ نامعتبر یعنی «تاریخچه‌ای نداریم»، نه «هرچه بود را نشان بده»
    return isRecent(parsed) ? parsed : []
  } catch {
    return []
  }
}

function writeRecent(list: RecentSearch[]): void {
  const key = recentKey(useAuthStore.getState().user)
  if (!key) return

  try {
    window.localStorage.setItem(key, JSON.stringify(list))
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

/*
 * ورود، خروج یا تعویضِ مجتمع: تاریخچه و نتیجه‌ی جست‌وجوی حساب یا مجتمعِ قبلی همان لحظه کنار می‌رود. تعویضِ
 * مجتمع خودش صفحه را از نو بار می‌کند (`enterNewContext`)؛ این برای ورود و خروجِ درونِ همان صفحه است.
 */
useAuthStore.subscribe((state, previous) => {
  if (recentKey(state.user) === recentKey(previous.user)) return

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

// تاریخچه‌ی بی‌صاحبِ نسخه‌های پیش از ۱۰/۸ — معلوم نیست مالِ کدام حساب و مجتمع بود
try {
  window.localStorage.removeItem(RECENT_KEY)
} catch {
  // فضای ذخیره‌سازی در دسترس نیست؛ بی‌خطر
}

/** رابطِ سازگار با نسخه‌ی قبلی؛ `hasResults` هم مثل قبل محاسبه می‌شود. */
export function useSearch() {
  const state = useSearchStore()
  return { ...state, hasResults: (state.results?.total ?? 0) > 0 }
}
