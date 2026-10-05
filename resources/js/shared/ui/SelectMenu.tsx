import { forwardRef, useEffect, useId, useRef, useState, type KeyboardEvent } from 'react'
import { Check, ChevronDown } from 'lucide-react'

/**
 * فهرستِ انتخابیِ سفارشی — جایگزینِ `<select>` ِ مرورگر (مرحلهٔ ۸ · فاز ۵ · بندِ ۷).
 *
 * الگوی «select-only combobox» ِ WAI-ARIA: دکمه‌ای با `role="combobox"`
 * و فهرستی با `role="listbox"`. فوکوس روی دکمه می‌ماند و گزینه‌ی فعال با
 * `aria-activedescendant` اعلام می‌شود — صفحه‌خوان همان را می‌خواند که یک
 * select ِ بومی.
 *
 * کیبورد (مثلِ select ِ بومی):
 *   بسته:  ↓ ↑ Enter Space ← باز؛ Home/End ← باز روی اولی/آخری؛ حرف ← پرش
 *   باز:   ↓ ↑ Home End حرکت؛ Enter/Space انتخاب؛ Esc بستن؛ Tab انتخاب و رفتن
 *
 * کلیک بیرون می‌بندد. راست‌به‌چپ، تیره/روشن از متغیرهای رنگ، و هر گزینه
 * دست‌کم ‎۴۴px برای انگشت.
 */
export interface SelectOption {
  value: string
  label: string
  description?: string
}

interface SelectMenuProps {
  label: string
  options: readonly SelectOption[]
  value: string
  onChange: (value: string) => void
  onBlur?: () => void
  placeholder?: string
  error?: string
  name?: string
}

export const SelectMenu = forwardRef<HTMLButtonElement, SelectMenuProps>(
  ({ label, options, value, onChange, onBlur, placeholder = 'انتخاب کنید', error, name }, ref) => {
    const id = useId()
    const labelId = `${id}-label`
    const listId = `${id}-list`
    const errorId = `${id}-error`
    const optionId = (index: number) => `${id}-option-${index}`

    const [open, setOpen] = useState(false)
    const [active, setActive] = useState(-1)
    const rootRef = useRef<HTMLDivElement>(null)
    const typed = useRef({ text: '', at: 0 })

    const selectedIndex = options.findIndex((o) => o.value === value)
    const selected = selectedIndex >= 0 ? options[selectedIndex] : null

    // کلیک بیرون می‌بندد
    useEffect(() => {
      if (!open) return
      const onPointer = (event: PointerEvent) => {
        if (!rootRef.current?.contains(event.target as Node)) setOpen(false)
      }
      document.addEventListener('pointerdown', onPointer)
      return () => document.removeEventListener('pointerdown', onPointer)
    }, [open])

    // گزینه‌ی فعال همیشه در دید
    useEffect(() => {
      if (open && active >= 0) {
        document.getElementById(`${id}-option-${active}`)?.scrollIntoView?.({ block: 'nearest' })
      }
    }, [id, open, active])

    const openAt = (index: number) => {
      setActive(Math.max(0, Math.min(options.length - 1, index)))
      setOpen(true)
    }

    const choose = (index: number) => {
      const option = options[index]
      if (option) onChange(option.value)
      setOpen(false)
    }

    /** پرش با حرفِ اول؛ چند حرفِ پشتِ هم (کمتر از ۷۰۰ms) یک عبارت‌اند */
    const typeahead = (key: string) => {
      const now = Date.now()
      typed.current.text = now - typed.current.at < 700 ? typed.current.text + key : key
      typed.current.at = now
      const start = open ? active + 1 : selectedIndex + 1
      const ordered = [...options.slice(start), ...options.slice(0, start)]
      const match = ordered.find((o) => o.label.startsWith(typed.current.text))
      return match ? options.indexOf(match) : -1
    }

    const onKeyDown = (event: KeyboardEvent<HTMLButtonElement>) => {
      const { key } = event

      if (!open) {
        if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(key)) {
          event.preventDefault()
          openAt(selectedIndex >= 0 ? selectedIndex : 0)
        } else if (key === 'Home' || key === 'End') {
          event.preventDefault()
          openAt(key === 'Home' ? 0 : options.length - 1)
        } else if (key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
          const index = typeahead(key)
          if (index >= 0) openAt(index)
        }
        return
      }

      switch (key) {
        case 'ArrowDown':
          event.preventDefault()
          setActive((i) => Math.min(options.length - 1, i + 1))
          break
        case 'ArrowUp':
          event.preventDefault()
          setActive((i) => Math.max(0, i - 1))
          break
        case 'Home':
        case 'End':
          event.preventDefault()
          setActive(key === 'Home' ? 0 : options.length - 1)
          break
        case 'Enter':
        case ' ':
          event.preventDefault()
          choose(active)
          break
        case 'Escape':
          event.preventDefault()
          setOpen(false)
          break
        case 'Tab':
          // مثلِ select ِ بومی: انتخاب و رفتن به بعدی (پیش‌فرضِ Tab نگه داشته می‌شود)
          choose(active)
          break
        default:
          if (key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
            const index = typeahead(key)
            if (index >= 0) setActive(index)
          }
      }
    }

    return (
      <div ref={rootRef} className="relative flex flex-col gap-1.5">
        <span
          id={labelId}
          className="text-[13px] font-medium"
          style={{ color: 'var(--text-secondary)' }}
        >
          {label}
        </span>

        <button
          ref={ref}
          type="button"
          name={name}
          role="combobox"
          aria-labelledby={labelId}
          aria-haspopup="listbox"
          aria-expanded={open}
          aria-controls={listId}
          aria-activedescendant={open && active >= 0 ? optionId(active) : undefined}
          aria-invalid={Boolean(error) || undefined}
          aria-describedby={error ? errorId : undefined}
          onClick={() => (open ? setOpen(false) : openAt(selectedIndex >= 0 ? selectedIndex : 0))}
          onKeyDown={onKeyDown}
          onBlur={onBlur}
          className="field flex w-full items-center justify-between gap-2 rounded-xl px-3 py-2.5 text-right text-[13.5px]"
        >
          <span
            className="truncate"
            style={{ color: selected ? 'var(--text-primary)' : 'var(--text-tertiary)' }}
          >
            {selected?.label ?? placeholder}
          </span>
          <ChevronDown
            size={16}
            aria-hidden="true"
            className="shrink-0 transition-transform"
            style={{
              color: 'var(--text-tertiary)',
              transform: open ? 'rotate(180deg)' : undefined,
            }}
          />
        </button>

        <ul
          id={listId}
          role="listbox"
          aria-labelledby={labelId}
          tabIndex={-1}
          hidden={!open}
          className="absolute inset-x-0 top-full z-30 mt-2 max-h-[23rem] overflow-y-auto rounded-2xl border p-1.5 shadow-xl"
          style={{ backgroundColor: 'var(--surface-overlay)', borderColor: 'var(--border-subtle)' }}
        >
          {options.map((option, index) => {
            const isSelected = option.value === value
            const isActive = index === active
            return (
              <li
                key={option.value}
                id={optionId(index)}
                role="option"
                aria-selected={isSelected}
                // pointerdown نه click: پیش از blur ِ دکمه، تا فهرست بسته نشده باشد
                onPointerDown={(event) => {
                  event.preventDefault()
                  choose(index)
                }}
                onPointerMove={() => setActive(index)}
                className="flex min-h-11 cursor-pointer items-center gap-3 rounded-xl px-3 py-2"
                style={{
                  backgroundColor: isActive
                    ? 'color-mix(in srgb, var(--color-brand-500) 12%, transparent)'
                    : undefined,
                }}
              >
                <span className="min-w-0 flex-1">
                  <span
                    className="block text-[13.5px] font-semibold"
                    style={{ color: isSelected ? 'var(--color-brand-600)' : 'var(--text-primary)' }}
                  >
                    {option.label}
                  </span>
                  {option.description && (
                    <span
                      className="mt-0.5 block text-xs leading-5"
                      style={{ color: 'var(--text-tertiary)' }}
                    >
                      {option.description}
                    </span>
                  )}
                </span>
                {isSelected && (
                  <Check
                    size={16}
                    aria-hidden="true"
                    className="shrink-0"
                    style={{ color: 'var(--color-brand-500)' }}
                  />
                )}
              </li>
            )
          })}
        </ul>

        {error && (
          <p id={errorId} role="alert" className="text-xs" style={{ color: 'var(--color-danger)' }}>
            {error}
          </p>
        )}
      </div>
    )
  },
)
SelectMenu.displayName = 'SelectMenu'
