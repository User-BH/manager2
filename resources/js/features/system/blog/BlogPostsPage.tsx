import { useDeferredValue, useState } from 'react'
import { Link } from 'react-router-dom'
import { ExternalLink, Pencil, Plus, Search, Trash2 } from 'lucide-react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Card } from '@/shared/ui/Card'
import { EmptyState, ErrorState } from '@/shared/ui/PageState'
import { Pager } from '@/shared/ui/Pager'
import { TableSkeleton } from '@/shared/ui/Skeleton'
import { useDebounce, useDocumentTitle } from '@/shared/hooks'
import { useApiAction } from '@/shared/hooks/useAction'
import { api } from '@/shared/lib/api'
import { queryKeys } from '@/shared/lib/queryKeys'
import { BlogCategoriesCard } from './BlogCategoriesCard'
import type { BlogPostRow, BlogPostsResponse } from './types'

const STATUS_FILTERS = [
  { value: '', label: 'همه' },
  { value: 'published', label: 'منتشرشده' },
  { value: 'draft', label: 'پیش‌نویس' },
] as const

/**
 * فهرستِ مقاله‌های بلاگ برای سوپرادمین (مرحلهٔ ۶ · فاز ۱۱).
 *
 * «مشاهده» صفحه‌ی واقعیِ عمومی را باز می‌کند — برای پیش‌نویس هم؛ سرور آن را
 * فقط به سوپرادمین و با noindex نشان می‌دهد.
 */
export function BlogPostsPage() {
  useDocumentTitle('بلاگ')

  const [q, setQ] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const search = useDeferredValue(useDebounce(q, 400))

  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.blog.posts({ q: search, status, page }),
    queryFn: ({ signal }) =>
      api<BlogPostsResponse>(
        `/system/blog/posts?q=${encodeURIComponent(search)}&status=${status}&page=${page}`,
        { signal },
      ),
    placeholderData: keepPreviousData,
  })

  const { call } = useApiAction()

  function remove(post: BlogPostRow) {
    void call(
      `/system/blog/posts/${post.id}`,
      { method: 'DELETE' },
      {
        key: post.id,
        confirm: {
          title: 'حذفِ مقاله',
          text: `«${post.title}» برای همیشه حذف شود؟ پیوندهای بیرونی به آن ۴۰۴ می‌شوند.`,
          confirmLabel: 'حذف',
          danger: true,
        },
        success: 'مقاله حذف شد.',
        errorFallback: 'حذفِ مقاله ممکن نشد.',
        invalidate: [queryKeys.blog.all()],
      },
    )
  }

  return (
    <div className="flex flex-col gap-5">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
            بلاگ
          </h1>
          <p className="mt-1 text-body" style={{ color: 'var(--text-tertiary)' }}>
            مقاله‌های عمومیِ سایت؛ پیش‌نویس فقط برای شما دیده می‌شود.
          </p>
        </div>
        <Link
          to="/system/blog/new"
          className="flex items-center gap-2 rounded-xl px-4 py-2 text-small font-semibold text-white"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          <Plus size={16} /> مقاله‌ی تازه
        </Link>
      </header>

      <div className="flex flex-wrap items-center gap-3">
        <div className="relative min-w-0 flex-1">
          <Search
            size={17}
            className="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2"
            style={{ color: 'var(--text-tertiary)' }}
          />
          <input
            value={q}
            onChange={(event) => {
              setQ(event.target.value)
              setPage(1)
            }}
            aria-label="جست‌وجو در عنوانِ مقاله‌ها"
            placeholder="جست‌وجو در عنوان…"
            className="w-full rounded-xl border py-2.5 pr-10 pl-3 text-small"
            style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
          />
        </div>
        <div role="group" aria-label="وضعیت" className="flex gap-1.5">
          {STATUS_FILTERS.map((filter) => (
            <button
              key={filter.value}
              type="button"
              aria-pressed={status === filter.value}
              onClick={() => {
                setStatus(filter.value)
                setPage(1)
              }}
              className="rounded-lg border px-3 py-1.5 text-small"
              style={{
                borderColor: 'var(--border-subtle)',
                backgroundColor: status === filter.value ? 'var(--tone-brand-bg)' : 'transparent',
                color: 'var(--text-secondary)',
              }}
            >
              {filter.label}
            </button>
          ))}
        </div>
      </div>

      <Card>
        {isLoading ? (
          <TableSkeleton rows={5} />
        ) : error ? (
          <ErrorState error={error} onRetry={() => void refetch()} />
        ) : !data || data.data.length === 0 ? (
          <EmptyState
            message={q || status ? 'مقاله‌ای با این فیلتر نیست.' : 'هنوز مقاله‌ای ننوشته‌اید.'}
          />
        ) : (
          <ul className="flex flex-col divide-y" style={{ borderColor: 'var(--border-subtle)' }}>
            {data.data.map((post) => (
              <li key={post.id} className="flex flex-wrap items-center gap-3 py-3">
                <div className="min-w-0 flex-1">
                  <p className="font-semibold" style={{ color: 'var(--text-primary)' }}>
                    {post.title}
                  </p>
                  <p className="text-tiny" style={{ color: 'var(--text-tertiary)' }}>
                    <StatusLabel post={post} />
                    {post.category && ` · ${post.category}`}
                    {` · ویرایش: ${post.updatedAt}`}
                  </p>
                </div>
                <a
                  href={`/blog/${encodeURIComponent(post.slug)}`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="flex items-center gap-1 rounded-lg border px-2.5 py-1 text-small"
                  style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
                >
                  <ExternalLink size={14} /> مشاهده
                </a>
                <Link
                  to={`/system/blog/${post.id}`}
                  className="flex items-center gap-1 rounded-lg border px-2.5 py-1 text-small"
                  style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
                >
                  <Pencil size={14} /> ویرایش
                </Link>
                <button
                  type="button"
                  onClick={() => remove(post)}
                  aria-label={`حذفِ ${post.title}`}
                  className="rounded-lg border p-1.5"
                  style={{ borderColor: 'var(--border-subtle)', color: 'var(--color-danger)' }}
                >
                  <Trash2 size={14} />
                </button>
              </li>
            ))}
          </ul>
        )}

        {data && <Pager meta={data.meta} onChange={setPage} noun="مقاله" />}
      </Card>

      <BlogCategoriesCard />
    </div>
  )
}

function StatusLabel({ post }: { post: BlogPostRow }) {
  if (post.status === 'draft') return <span>پیش‌نویس</span>

  return post.scheduled ? (
    <span>زمان‌بندی‌شده برای {post.publishedAt}</span>
  ) : (
    <span style={{ color: 'var(--state-success)' }}>منتشرشده · {post.publishedAt}</span>
  )
}
