import { AlertCircle, Loader2, Inbox } from 'lucide-react'

/** اسکلت بارگذاری — به‌جای اسپینر خالی، شکل تقریبی محتوا را نشان می‌دهد. */
export function LoadingState({ rows = 3 }: { rows?: number }) {
  return (
    <div className="flex flex-col gap-3" role="status" aria-label="در حال بارگذاری">
      {Array.from({ length: rows }).map((_, i) => (
        <div
          key={i}
          className="h-16 animate-pulse rounded-2xl"
          style={{ backgroundColor: 'var(--surface-sunken)' }}
        />
      ))}
    </div>
  )
}

export function InlineSpinner() {
  return (
    <div className="flex items-center justify-center py-12">
      <Loader2 size={24} className="animate-spin" style={{ color: 'var(--color-brand-500)' }} />
    </div>
  )
}

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <div
      className="flex flex-col items-center gap-3 rounded-2xl border p-10 text-center"
      style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
    >
      <AlertCircle size={30} style={{ color: 'var(--color-danger)' }} />
      <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
        {message}
      </p>
      {onRetry && (
        <button
          onClick={onRetry}
          className="mt-1 rounded-xl px-4 py-2 text-xs font-semibold text-white"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          تلاش دوباره
        </button>
      )}
    </div>
  )
}

export function EmptyState({ message, hint }: { message: string; hint?: string }) {
  return (
    <div className="flex flex-col items-center gap-2 py-12 text-center">
      <Inbox size={28} style={{ color: 'var(--text-tertiary)' }} />
      <p className="text-sm font-medium" style={{ color: 'var(--text-secondary)' }}>
        {message}
      </p>
      {hint && (
        <p className="text-xs" style={{ color: 'var(--text-tertiary)' }}>
          {hint}
        </p>
      )}
    </div>
  )
}
