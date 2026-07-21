import { useDocumentTitle } from '@/hooks'

interface PlaceholderPageProps {
  title: string
}

export function PlaceholderPage({ title }: PlaceholderPageProps) {
  useDocumentTitle(title)

  return (
    <div
      className="flex h-[70vh] flex-col items-center justify-center rounded-2xl border text-center"
      style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
    >
      <h1 className="text-lg font-bold" style={{ color: 'var(--text-primary)' }}>
        {title}
      </h1>
      <p className="mt-2 text-sm" style={{ color: 'var(--text-tertiary)' }}>
        این بخش به‌زودی ساخته می‌شود.
      </p>
    </div>
  )
}
