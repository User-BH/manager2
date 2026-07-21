import { useEffect, useState } from 'react'

/**
 * مقدار ورودی را با تاخیر مشخص (پیش‌فرض ۴۰۰ms) برمی‌گرداند.
 * مناسب برای جستجو، فیلتر و هر جایی که نباید با هر کلیدزدن، درخواست زده شود.
 */
export function useDebounce<T>(value: T, delay = 400): T {
  const [debouncedValue, setDebouncedValue] = useState(value)

  useEffect(() => {
    const timeoutId = window.setTimeout(() => setDebouncedValue(value), delay)
    return () => window.clearTimeout(timeoutId)
  }, [value, delay])

  return debouncedValue
}
