import { useEffect, useState } from 'react'

/**
 * درصد اسکرول‌شده‌ی کل صفحه را برمی‌گرداند (بین ۰ تا ۱۰۰).
 * مناسب برای نوار پیشرفت بالای صفحه یا افکت‌های وابسته به اسکرول کلی.
 */
export function useScrollProgress(): number {
  const [progress, setProgress] = useState(0)

  useEffect(() => {
    function handleScroll() {
      const scrollTop = window.scrollY
      const docHeight = document.documentElement.scrollHeight - window.innerHeight
      setProgress(docHeight > 0 ? (scrollTop / docHeight) * 100 : 0)
    }

    handleScroll()
    window.addEventListener('scroll', handleScroll, { passive: true })
    return () => window.removeEventListener('scroll', handleScroll)
  }, [])

  return progress
}
