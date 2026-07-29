import { useCallback, useState } from 'react'

/**
 * مدیریت ساده یک مقدار boolean با امکان toggle، set مستقیم true/false.
 * مثال: const [open, toggleOpen, setOpen] = useToggle(false)
 */
export function useToggle(initialValue = false) {
  const [value, setValue] = useState(initialValue)

  const toggle = useCallback(() => setValue((prev) => !prev), [])

  return [value, toggle, setValue] as const
}
