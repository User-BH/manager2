import { useEffect, type RefObject } from 'react'

/**
 * با کلیک خارج از المنت مرجع، callback را اجرا می‌کند.
 * مناسب برای بستن منوهای کشویی، دراپ‌داون و پاپ‌آورها.
 */
export function useClickOutside<T extends HTMLElement>(
  ref: RefObject<T | null>,
  onClickOutside: () => void,
) {
  useEffect(() => {
    function handleClick(event: MouseEvent) {
      if (ref.current && !ref.current.contains(event.target as Node)) {
        onClickOutside()
      }
    }

    document.addEventListener('mousedown', handleClick)
    return () => document.removeEventListener('mousedown', handleClick)
  }, [ref, onClickOutside])
}
