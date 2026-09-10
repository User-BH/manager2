import { useCallback, useEffect, useState } from 'react'

type SetValue<T> = T | ((prev: T) => T)

/**
 * مقداری را در localStorage نگه می‌دارد و state ری‌اکت را با آن همگام می‌کند.
 * بین تب‌های مختلف مرورگر هم با event "storage" سینک می‌شود.
 *
 * ─── 🔴 چرا `isValid` اجباری است (مرحلهٔ ۵، فاز ۳ — بندهای ۱۴ و ۱۶) ────────
 * نسخهٔ قبلی این بود:
 *
 *     return item ? (JSON.parse(item) as T) : initialValue
 *
 * ⚠️ آن `as T` یک **ادعا** بود، نه یک بررسی. `localStorage` دادهٔ خارجی
 * است: کاربر می‌تواند دستی عوضش کند، نسخهٔ قدیمیِ برنامه ممکن است شکلِ
 * دیگری نوشته باشد، و افزونهٔ مرورگر هم به آن دسترسی دارد.
 *
 * 🔴 نتیجه‌اش این بود که یک مقدارِ خراب مستقیم وارد رندر می‌شد. برای
 * ماشین‌حساب یعنی `history.map(...)` روی چیزی که آرایه نیست — و صفحهٔ
 * سفید.
 *
 * ⚠️ پارامتر **اختیاری نشد** عمداً: پیش‌فرضِ امن‌نبودن، همان چیزی است که
 * این حفره را ساخته بود. حالا هر مصرف‌کننده باید صریح بگوید شکلِ درست
 * چیست.
 */
export function useLocalStorage<T>(
  key: string,
  initialValue: T,
  isValid: (value: unknown) => value is T,
) {
  const readValue = useCallback((): T => {
    if (typeof window === 'undefined') return initialValue

    try {
      const item = window.localStorage.getItem(key)
      if (!item) return initialValue

      const parsed: unknown = JSON.parse(item)

      // 🔴 دادهٔ نامعتبر بی‌سروصدا به مقدارِ اولیه برمی‌گردد، نه به رندر
      return isValid(parsed) ? parsed : initialValue
    } catch {
      return initialValue
    }
  }, [key, initialValue, isValid])

  const [storedValue, setStoredValue] = useState<T>(readValue)

  const setValue = useCallback(
    (value: SetValue<T>) => {
      setStoredValue((prev) => {
        const nextValue = value instanceof Function ? value(prev) : value
        try {
          window.localStorage.setItem(key, JSON.stringify(nextValue))
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
