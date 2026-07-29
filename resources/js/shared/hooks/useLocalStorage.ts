import { useCallback, useEffect, useState } from 'react'

type SetValue<T> = T | ((prev: T) => T)

/**
 * مقداری را در localStorage نگه می‌دارد و state ری‌اکت را با آن همگام می‌کند.
 * بین تب‌های مختلف مرورگر هم با event "storage" سینک می‌شود.
 */
export function useLocalStorage<T>(key: string, initialValue: T) {
  const readValue = useCallback((): T => {
    if (typeof window === 'undefined') return initialValue
    try {
      const item = window.localStorage.getItem(key)
      return item ? (JSON.parse(item) as T) : initialValue
      // eslint-disable-next-line @typescript-eslint/no-unused-vars
    } catch {
      return initialValue
    }
  }, [key, initialValue])

  const [storedValue, setStoredValue] = useState<T>(readValue)

  const setValue = useCallback(
    (value: SetValue<T>) => {
      setStoredValue((prev) => {
        const nextValue = value instanceof Function ? value(prev) : value
        try {
          window.localStorage.setItem(key, JSON.stringify(nextValue))
          // eslint-disable-next-line @typescript-eslint/no-unused-vars
        } catch {
          // فضای ذخیره‌سازی پر است یا در دسترس نیست - بی‌خطر نادیده می‌گیریم
        }
        return nextValue
      })
    },
    [key],
  )

  useEffect(() => {
    function handleStorageChange(e: StorageEvent) {
      if (e.key === key) setStoredValue(readValue())
    }
    window.addEventListener('storage', handleStorageChange)
    return () => window.removeEventListener('storage', handleStorageChange)
  }, [key, readValue])

  return [storedValue, setValue] as const
}
