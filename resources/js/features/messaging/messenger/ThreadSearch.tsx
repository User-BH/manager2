import { useEffect, useRef, useState } from 'react'
import { Search, X } from 'lucide-react'
import { useDebounce } from '@/shared/hooks'
import { messageActions } from './messageActions'
import type { SearchHit } from './types'

/**
 * جست‌وجو در همین گفت‌وگو (مرحلهٔ ۶ · فاز ۱۴).
 *
 * نتیجه فقط تکه‌متن و زمان است — همان قاعده‌ی دیدِ سرور: پیامِ پنهان برای
 * ساکن و پیامِ حذف‌شده برای همه پیدا نمی‌شوند. انتخابِ نتیجه، اگر آن پیام در
 * فهرست بار شده باشد، به آن می‌پرد.
 */
export function ThreadSearch({
  conversationId,
  onPick,
  onClose,
}: {
  conversationId: number
  onPick: (id: number) => void
  onClose: () => void
}) {
  const [query, setQuery] = useState('')
  const [results, setResults] = useState<{ hits: SearchHit[]; note: string | null } | null>(null)
  const debounced = useDebounce(query.trim(), 350)
  const inputRef = useRef<HTMLInputElement>(null)

  // کاربر خودش «جست‌وجو» را زده؛ فوکوس همان‌جاست — حتی روی موبایل
  useEffect(() => inputRef.current?.focus(), [])

  useEffect(() => {
    if (debounced.length < 2) return

    const controller = new AbortController()
    messageActions
      .search(conversationId, debounced, controller.signal)
      .then((data) => setResults({ hits: data.results, note: data.message ?? null }))
      .catch(() => {
        if (!controller.signal.aborted) setResults({ hits: [], note: 'جست‌وجو انجام نشد.' })
      })

    return () => controller.abort()
  }, [conversationId, debounced])

  const visible = debounced.length >= 2 ? results : null

  return (
    <div
      className="rounded-xl border p-2"
      style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
    >
      <div className="flex items-center gap-2">
        <Search size={15} style={{ color: 'var(--text-tertiary)' }} aria-hidden="true" />
        <input
          ref={inputRef}
          type="search"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          onKeyDown={(event) => event.key === 'Escape' && onClose()}
          aria-label="جست‌وجو در این گفت‌وگو"
          placeholder="جست‌وجو در این گفت‌وگو…"
          className="min-w-0 flex-1 bg-transparent py-1.5 text-body outline-none"
          style={{ color: 'var(--text-primary)' }}
        />
        <button type="button" onClick={onClose} aria-label="بستنِ جست‌وجو">
          <X size={15} />
        </button>
      </div>

      {visible && (
        <ul className="mt-2 max-h-56 overflow-y-auto" aria-label="نتیجه‌های جست‌وجو">
          {visible.hits.length === 0 ? (
            <li className="p-2 text-small" style={{ color: 'var(--text-tertiary)' }}>
              {visible.note ?? 'پیامی پیدا نشد.'}
            </li>
          ) : (
            visible.hits.map((hit) => (
              <li key={hit.id}>
                <button
                  type="button"
                  onClick={() => onPick(hit.id)}
                  className="block w-full rounded-lg p-2 text-right hover:bg-[var(--surface-sunken)]"
                >
                  <span className="block text-tiny" style={{ color: 'var(--text-tertiary)' }}>
                    {hit.authorName} · {hit.sentAt}
                  </span>
                  <span className="block text-small" style={{ color: 'var(--text-primary)' }}>
                    {hit.excerpt}
                  </span>
                </button>
              </li>
            ))
          )}
        </ul>
      )}
    </div>
  )
}
