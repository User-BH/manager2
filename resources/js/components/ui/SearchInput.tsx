import { useEffect, useMemo, useState } from 'react'
import debounce from 'lodash/debounce'
import { Search, X } from 'lucide-react'

interface SearchInputProps {
  onSearch: (query: string) => void
  placeholder?: string
  delay?: number
}

/**
 * جستجو با تاخیر — هر حرفی که تایپ می‌شود یک درخواست به سرور نمی‌فرستد.
 * debounce از lodash گرفته شده و با useMemo پایدار نگه داشته می‌شود تا هر
 * رندر یک نسخه‌ی تازه (و بی‌اثر) نسازد.
 */
export function SearchInput({ onSearch, placeholder = 'جستجو…', delay = 400 }: SearchInputProps) {
  const [value, setValue] = useState('')

  const debounced = useMemo(() => debounce(onSearch, delay), [onSearch, delay])

  useEffect(() => () => debounced.cancel(), [debounced])

  function update(next: string) {
    setValue(next)
    debounced(next.trim())
  }

  return (
    <div className="relative w-full sm:max-w-xs">
      <Search
        size={16}
        className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2"
        style={{ color: 'var(--text-tertiary)' }}
      />
      <input
        value={value}
        onChange={(e) => update(e.target.value)}
        placeholder={placeholder}
        className="w-full rounded-xl border py-2.5 pl-9 pr-9 text-[13.5px] outline-none transition-all focus:ring-2"
        style={{
          backgroundColor: 'var(--surface-sunken)',
          borderColor: 'var(--border-subtle)',
          color: 'var(--text-primary)',
          ['--tw-ring-color' as string]: 'var(--ring-focus)',
        }}
      />
      {value && (
        <button
          onClick={() => update('')}
          aria-label="پاک کردن جستجو"
          className="absolute left-2.5 top-1/2 flex h-5 w-5 -translate-y-1/2 items-center justify-center rounded-full"
          style={{ color: 'var(--text-tertiary)' }}
        >
          <X size={13} />
        </button>
      )}
    </div>
  )
}
