import { useCallback, useEffect, useRef, useState } from 'react'
import { api } from '@/shared/lib/api'
import { ApiError } from '@/shared/lib/api'

export interface CursorPage<T> {
  data: T[]
  hasMore: boolean
  nextCursor: number | null
}

interface State<T> {
  items: T[]
  hasMore: boolean
  isLoading: boolean
  isLoadingMore: boolean
  error: string | null
}

/**
 * فهرستِ «ادامه بده» با نشانگر (R30).
 *
 * ─── چرا جای شماره‌ی صفحه را می‌گیرد ───────────────────────────────────────
 * لاگِ فعالیت و رویدادهای خطا جدول‌های **افزایشی**اند. با شماره‌ی صفحه،
 * ردیف‌های تازه‌ای که بین دو درخواست ثبت می‌شوند فهرست را جابه‌جا می‌کنند و
 * کاربر در صفحه‌ی ۲ همان چیزی را می‌بیند که در صفحه‌ی ۱ دیده بود — و به
 * همان تعداد، ردیفِ قدیمی‌تر را هرگز نمی‌بیند.
 *
 * ─── چرا خودمان و نه `useInfiniteQuery` ────────────────────────────────────
 * TanStack Query هست و `useInfiniteQuery` هم دارد، ولی برای این دو صفحه
 * کشِ چندصفحه‌ای بیشتر ضرر دارد تا سود: کاربر می‌خواهد فهرستِ **تازه** را
 * ببیند و کشِ صفحه‌ی ۳ از ده دقیقه پیش دقیقاً همان چیزی است که نمی‌خواهد.
 * این قلاب عمداً کش ندارد و هر بار از ابتدا می‌خواند.
 */
export function useCursorList<T>(path: string, params: Record<string, string> = {}) {
  const [state, setState] = useState<State<T>>({
    items: [],
    hasMore: false,
    isLoading: true,
    isLoadingMore: false,
    error: null,
  })

  const cursorRef = useRef<number | null>(null)

  /*
   * شمارنده‌ی بازخوانی.
   *
   * پس از تغییری روی خودِ ردیف‌ها (مثلاً «بررسی شد» روی یک خطا) فهرست باید
   * از ابتدا خوانده شود. چون این قلاب کش ندارد، چیزی برای `invalidate`
   * وجود ندارد و یک شمارنده در وابستگیِ افکت ساده‌ترین راه است.
   */
  const [reloadToken, setReloadToken] = useState(0)

  /*
   * پارامترها به رشته تبدیل می‌شوند تا وابستگیِ افکت پایدار بماند.
   * بدونِ این، شیءِ تازه در هر رندر یعنی واکشیِ بی‌پایان.
   */
  const query = new URLSearchParams(params).toString()

  const load = useCallback(
    async (cursor: number | null, signal?: AbortSignal) => {
      const separator = path.includes('?') ? '&' : '?'
      const url = `${path}${separator}${query}${cursor ? `&cursor=${cursor}` : ''}`

      return api<CursorPage<T>>(url, { signal })
    },
    [path, query],
  )

  // بارگذاری اول، و هر بار که فیلترها عوض شوند
  useEffect(() => {
    const controller = new AbortController()

    setState((s) => ({ ...s, isLoading: true, error: null }))
    cursorRef.current = null

    load(null, controller.signal)
      .then((page) => {
        cursorRef.current = page.nextCursor
        setState({
          items: page.data,
          hasMore: page.hasMore,
          isLoading: false,
          isLoadingMore: false,
          error: null,
        })
      })
      .catch((err: unknown) => {
        if (controller.signal.aborted) return

        setState((s) => ({
          ...s,
          isLoading: false,
          error: err instanceof ApiError ? err.message : 'دریافت فهرست ناموفق بود.',
        }))
      })

    return () => controller.abort()
  }, [load, reloadToken])

  const loadMore = useCallback(async () => {
    if (cursorRef.current === null) return

    setState((s) => ({ ...s, isLoadingMore: true }))

    try {
      const page = await load(cursorRef.current)
      cursorRef.current = page.nextCursor

      setState((s) => ({
        ...s,
        /*
         * ردیفِ تکراری کنار گذاشته می‌شود.
         *
         * با نشانگر نباید پیش بیاید، ولی اگر دو کلیک سریع پشتِ هم برود،
         * همان درخواست دو بار می‌رفت و ردیف‌ها دو بار در فهرست می‌نشستند —
         * که در React با کلیدِ تکراری خطا هم می‌دهد.
         */
        items: dedupe([...s.items, ...page.data]),
        hasMore: page.hasMore,
        isLoadingMore: false,
      }))
    } catch (err) {
      setState((s) => ({
        ...s,
        isLoadingMore: false,
        error: err instanceof ApiError ? err.message : 'دریافت ادامه‌ی فهرست ناموفق بود.',
      }))
    }
  }, [load])

  const reload = useCallback(() => setReloadToken((n) => n + 1), [])

  return { ...state, loadMore, reload }
}

/** حذفِ ردیف‌های با شناسه‌ی تکراری، با حفظِ ترتیب. */
function dedupe<T>(rows: T[]): T[] {
  const seen = new Set<unknown>()

  return rows.filter((row) => {
    const id = (row as { id?: unknown }).id

    if (id === undefined) return true
    if (seen.has(id)) return false

    seen.add(id)

    return true
  })
}
