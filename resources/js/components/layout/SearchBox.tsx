import { useEffect, useState } from 'react'
import { Search, X } from 'lucide-react'
import { useDebounce } from '@/hooks'

interface SearchBoxProps {
  onSearch?: (debouncedQuery: string) => void
  placeholder?: string
}

export function SearchBox({
  onSearch,
  placeholder = 'جستجو در واحدها، ساکنین، قبوض...',
}: SearchBoxProps) {
  const [query, setQuery] = useState('')
  const debouncedQuery = useDebounce(query, 400)

  useEffect(() => {
    onSearch?.(debouncedQuery.trim())
  }, [debouncedQuery, onSearch])

  return (
    <div className="relative hidden w-full max-w-sm sm:block">
      <Search
        size={17}
        className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2"
        style={{ color: 'var(--text-tertiary)' }}
      />
      <input
        type="text"
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        placeholder={placeholder}
        className="w-full rounded-xl border py-2 pr-10 pl-9 text-[13.5px] outline-none transition-all duration-200 focus:ring-2"
        style={{
          backgroundColor: 'var(--surface-sunken)',
          borderColor: 'var(--border-subtle)',
          color: 'var(--text-primary)',
          ['--tw-ring-color' as string]: 'var(--ring-focus)',
        }}
      />
      {query && (
        <button
          onClick={() => setQuery('')}
          className="absolute left-2.5 top-1/2 flex h-5 w-5 -translate-y-1/2 items-center justify-center rounded-full transition-colors hover:bg-(--border-subtle)"
          style={{ color: 'var(--text-tertiary)' }}
          aria-label="پاک کردن جستجو"
        >
          <X size={13} />
        </button>
      )}
    </div>
  )
}
