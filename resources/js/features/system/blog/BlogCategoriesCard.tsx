import { useQuery } from '@tanstack/react-query'
import { Pencil, Plus, Trash2 } from 'lucide-react'
import { Card } from '@/shared/ui/Card'
import { ErrorState, LoadingState } from '@/shared/ui/PageState'
import { useApiAction } from '@/shared/hooks/useAction'
import { api } from '@/shared/lib/api'
import { promptText } from '@/shared/lib/alert'
import { queryKeys } from '@/shared/lib/queryKeys'
import type { BlogCategory } from './types'

/**
 * دسته‌های بلاگ (مرحلهٔ ۶ · فاز ۱۱). فهرست کوچک است؛ نامک از نام ساخته و
 * پس از آن ثابت می‌ماند تا پیوندهای بیرونی نشکنند.
 */
export function BlogCategoriesCard() {
  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.blog.categories(),
    queryFn: ({ signal }) => api<{ data: BlogCategory[] }>('/system/blog/categories', { signal }),
  })

  const { call } = useApiAction()

  async function create() {
    const name = await promptText({
      title: 'دسته‌ی تازه',
      placeholder: 'مثلاً مالی',
      required: true,
    })
    if (name === null) return

    await call(
      '/system/blog/categories',
      { method: 'POST', body: { name } },
      {
        success: 'دسته ساخته شد.',
        errorFallback: 'ساختِ دسته ممکن نشد.',
        invalidate: [queryKeys.blog.all()],
      },
    )
  }

  async function rename(category: BlogCategory) {
    const name = await promptText({
      title: 'نامِ دسته',
      text: 'نامک (آدرس) عوض نمی‌شود.',
      defaultValue: category.name,
      required: true,
    })
    if (name === null || name === category.name) return

    await call(
      `/system/blog/categories/${category.id}`,
      { method: 'PUT', body: { name, description: category.description } },
      {
        key: category.id,
        success: 'دسته ذخیره شد.',
        errorFallback: 'ذخیره ممکن نشد.',
        invalidate: [queryKeys.blog.all()],
      },
    )
  }

  function remove(category: BlogCategory) {
    void call(
      `/system/blog/categories/${category.id}`,
      { method: 'DELETE' },
      {
        key: category.id,
        confirm: {
          title: 'حذفِ دسته',
          text: `«${category.name}» حذف شود؟ ${category.postsCount} مقاله‌اش بی‌دسته می‌شود و حذف نمی‌شود.`,
          confirmLabel: 'حذف',
          danger: true,
        },
        success: 'دسته حذف شد.',
        errorFallback: 'حذفِ دسته ممکن نشد.',
        invalidate: [queryKeys.blog.all()],
      },
    )
  }

  return (
    <Card
      title="دسته‌ها"
      actions={
        <button
          type="button"
          onClick={() => void create()}
          className="flex items-center gap-1 rounded-lg border px-2.5 py-1 text-small"
          style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
        >
          <Plus size={14} /> دسته‌ی تازه
        </button>
      }
    >
      {isLoading ? (
        <LoadingState rows={2} />
      ) : error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : (data?.data.length ?? 0) === 0 ? (
        <p className="text-small" style={{ color: 'var(--text-tertiary)' }}>
          هنوز دسته‌ای نساخته‌اید.
        </p>
      ) : (
        <ul className="flex flex-wrap gap-2">
          {data?.data.map((category) => (
            <li
              key={category.id}
              className="flex items-center gap-1.5 rounded-full border py-1 pr-3 pl-1.5 text-small"
              style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
            >
              {category.name} ({category.postsCount})
              <button
                type="button"
                onClick={() => void rename(category)}
                aria-label={`تغییرِ نامِ ${category.name}`}
                className="rounded-full p-1"
              >
                <Pencil size={12} />
              </button>
              <button
                type="button"
                onClick={() => remove(category)}
                aria-label={`حذفِ ${category.name}`}
                className="rounded-full p-1"
                style={{ color: 'var(--color-danger)' }}
              >
                <Trash2 size={12} />
              </button>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}
