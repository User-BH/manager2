import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '@/shared/lib/api'

interface UseApiState<T> {
  data: T | null
  error: string | null
  isLoading: boolean
  reload: () => void
  /**
   * به‌روزرسانیِ محلیِ داده بدون درخواستِ تازه — برای به‌روزرسانیِ خوش‌بینانه.
   * فوراً UI را عوض می‌کند؛ در صورت شکستِ درخواست، با `reload()` از سرور
   * بازگردانده می‌شود.
   */
  mutate: (updater: (current: T | null) => T | null) => void
}

/**
 * خواندن یک منبع از API با مدیریت وضعیت بارگذاری و خطا.
 *
 * درخواستِ در جریان هنگام تغییر مسیر یا پارامترها لغو (abort) می‌شود تا پاسخِ
 * دیرهنگامِ یک درخواست قدیمی، دادهٔ جدید را بازنویسی نکند.
 */
export function useApi<T>(path: string, deps: unknown[] = []): UseApiState<T> {
  const [data, setData] = useState<T | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [nonce, setNonce] = useState(0)

  const reload = useCallback(() => setNonce((n) => n + 1), [])

  const mutate = useCallback((updater: (current: T | null) => T | null) => {
    setData((current) => updater(current))
  }, [])

  useEffect(() => {
    const controller = new AbortController()
    setIsLoading(true)
    setError(null)

    api<T>(path, { signal: controller.signal })
      .then((result) => {
        setData(result)
        setIsLoading(false)
      })
      .catch((err: unknown) => {
        if (controller.signal.aborted) return

        setError(err instanceof ApiError ? err.message : 'ارتباط با سرور برقرار نشد.')
        setIsLoading(false)
      })

    return () => controller.abort()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [path, nonce, ...deps])

  return { data, error, isLoading, reload, mutate }
}
