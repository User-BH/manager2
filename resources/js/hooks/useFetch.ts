import { useEffect, useRef, useState, useCallback } from 'react'

interface UseFetchState<T> {
  data: T | null
  loading: boolean
  error: string | null
}

interface UseFetchOptions extends RequestInit {
  /** اگر false باشد، درخواست به‌صورت خودکار اجرا نمی‌شود (با refetch صدا زده می‌شود) */
  enabled?: boolean
}

/**
 * هوک عمومی برای فراخوانی API.
 * لغو خودکار درخواست قبلی هنگام unmount یا تغییر url را هندل می‌کند (AbortController).
 *
 * مثال:
 * const { data, loading, error, refetch } = useFetch<Unit[]>('/api/units')
 */
export function useFetch<T>(url: string | null, options: UseFetchOptions = {}) {
  const { enabled = true, ...fetchOptions } = options
  const [state, setState] = useState<UseFetchState<T>>({
    data: null,
    loading: false,
    error: null,
  })

  // برای جلوگیری از re-render بی‌مورد، آپشن‌ها را در ref نگه می‌داریم
  const optionsRef = useRef(fetchOptions)
  optionsRef.current = fetchOptions

  const fetchData = useCallback(
    async (signal?: AbortSignal) => {
      if (!url) return
      setState((prev) => ({ ...prev, loading: true, error: null }))

      try {
        const response = await fetch(url, { ...optionsRef.current, signal })
        if (!response.ok) {
          throw new Error(`خطای سرور: ${response.status}`)
        }
        const json = (await response.json()) as T
        setState({ data: json, loading: false, error: null })
      } catch (err) {
        if (err instanceof DOMException && err.name === 'AbortError') return
        const message = err instanceof Error ? err.message : 'خطای ناشناخته رخ داد'
        setState({ data: null, loading: false, error: message })
      }
    },
    [url],
  )

  useEffect(() => {
    if (!enabled || !url) return

    const controller = new AbortController()
    fetchData(controller.signal)

    return () => controller.abort()
  }, [url, enabled, fetchData])

  const refetch = useCallback(() => fetchData(), [fetchData])

  return { ...state, refetch }
}
