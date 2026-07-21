import { useEffect, useState } from 'react'

/**
 * مقدار boolean تطابق با یک media query را برمی‌گرداند و با تغییر اندازه صفحه به‌روز می‌شود.
 * مثال: const isMobile = useMediaQuery('(max-width: 1024px)')
 */
export function useMediaQuery(query: string): boolean {
  const [matches, setMatches] = useState(() => {
    if (typeof window === 'undefined') return false
    return window.matchMedia(query).matches
  })

  useEffect(() => {
    const mediaQueryList = window.matchMedia(query)
    const handleChange = () => setMatches(mediaQueryList.matches)

    handleChange()
    mediaQueryList.addEventListener('change', handleChange)
    return () => mediaQueryList.removeEventListener('change', handleChange)
  }, [query])

  return matches
}
