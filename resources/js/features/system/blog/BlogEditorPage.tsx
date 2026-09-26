import { useEffect, useRef, useState, type ChangeEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useForm, useWatch } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowRight, ExternalLink, ImagePlus, Loader2 } from 'lucide-react'
import { Card } from '@/shared/ui/Card'
import { SelectField, TextAreaField, TextField } from '@/shared/ui/Field'
import { ErrorState, LoadingState } from '@/shared/ui/PageState'
import { useDocumentTitle } from '@/shared/hooks'
import { api, ApiError } from '@/shared/lib/api'
import { alertError, toastSuccess } from '@/shared/lib/alert'
import { queryKeys } from '@/shared/lib/queryKeys'
import { uploadProblem } from '@/shared/lib/upload'
import {
  blogPostSchema,
  fromLocalInput,
  parseTags,
  toLocalInput,
  type BlogPostValues,
} from './schema'
import type { BlogCategory, BlogImageUpload, BlogPostDetail } from './types'

const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp']

const EMPTY: BlogPostValues = {
  title: '',
  slug: '',
  excerpt: '',
  body: '',
  categoryId: '',
  tags: '',
  status: 'draft',
  publishedAt: '',
  cover: '',
  metaTitle: '',
  metaDescription: '',
}

/**
 * نوشتن و ویرایشِ مقاله (مرحلهٔ ۶ · فاز ۱۱).
 *
 * متن مارک‌داون است و سرور هنگامِ ذخیره آن را به HTMLِ امن تبدیل می‌کند.
 * «پیش‌نمایش» خودِ صفحه‌ی عمومیِ پیش‌نویس است (فقط برای سوپرادمین، noindex)؛
 * پس آنچه دیده می‌شود دقیقاً همان است که منتشر خواهد شد.
 */
export function BlogEditorPage() {
  const { id } = useParams()
  const postId = id ? Number(id) : null
  useDocumentTitle(postId ? 'ویرایشِ مقاله' : 'مقاله‌ی تازه')

  const post = useQuery({
    queryKey: queryKeys.blog.post(postId ?? 0),
    queryFn: ({ signal }) =>
      api<{ post: BlogPostDetail }>(`/system/blog/posts/${postId}`, { signal }),
    enabled: postId !== null && Number.isInteger(postId),
  })

  const categories = useQuery({
    queryKey: queryKeys.blog.categories(),
    queryFn: ({ signal }) => api<{ data: BlogCategory[] }>('/system/blog/categories', { signal }),
  })

  if (postId !== null && post.isLoading) return <LoadingState rows={6} />
  if (post.error) return <ErrorState error={post.error} onRetry={() => void post.refetch()} />

  return (
    <BlogEditorForm
      key={post.data?.post.id ?? 'new'}
      initial={post.data?.post ?? null}
      categories={categories.data?.data ?? []}
    />
  )
}

function BlogEditorForm({
  initial,
  categories,
}: {
  initial: BlogPostDetail | null
  categories: BlogCategory[]
}) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const bodyRef = useRef<HTMLTextAreaElement | null>(null)
  const [uploading, setUploading] = useState<'cover' | 'body' | null>(null)
  const [savedSlug, setSavedSlug] = useState(initial?.slug ?? null)

  const {
    register,
    handleSubmit,
    setError,
    setValue,
    getValues,
    control,
    reset,
    formState: { errors, isSubmitting, isDirty },
  } = useForm<BlogPostValues>({
    resolver: zodResolver(blogPostSchema),
    defaultValues: initial
      ? {
          title: initial.title,
          slug: initial.slug,
          excerpt: initial.excerpt ?? '',
          body: initial.body,
          categoryId: initial.categoryId ? String(initial.categoryId) : '',
          tags: initial.tags.join('، '),
          status: initial.status,
          publishedAt: toLocalInput(initial.publishedAt),
          cover: initial.cover ?? '',
          metaTitle: initial.metaTitle ?? '',
          metaDescription: initial.metaDescription ?? '',
        }
      : EMPTY,
  })

  // ⚠️ هشدارِ ترکِ صفحه با تغییرِ ذخیره‌نشده (همان قاعده‌ی فرم‌های فاز ۴)
  useEffect(() => {
    if (!isDirty) return
    const warn = (event: BeforeUnloadEvent) => event.preventDefault()
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [isDirty])

  const cover = useWatch({ control, name: 'cover' })
  const bodyField = register('body')

  async function upload(file: File, target: 'cover' | 'body') {
    const problem = uploadProblem(file, IMAGE_EXTENSIONS, 3)
    if (problem) {
      alertError(new ApiError(problem, 422), problem)
      return
    }

    setUploading(target)
    try {
      const form = new FormData()
      form.append('image', file)
      const result = await api<BlogImageUpload>('/system/blog/images', {
        method: 'POST',
        body: form,
      })

      if (target === 'cover') {
        setValue('cover', result.file, { shouldDirty: true })
        return
      }

      // تصویر همان‌جا که مکان‌نما هست
      const textarea = bodyRef.current
      const body = getValues('body')
      const at = textarea?.selectionStart ?? body.length
      setValue('body', `${body.slice(0, at)}\n${result.markdown}\n${body.slice(at)}`, {
        shouldDirty: true,
      })
    } catch (error) {
      alertError(error, 'بارگذاریِ تصویر ممکن نشد.')
    } finally {
      setUploading(null)
    }
  }

  function onFile(event: ChangeEvent<HTMLInputElement>, target: 'cover' | 'body') {
    const file = event.target.files?.[0]
    event.target.value = ''
    if (file) void upload(file, target)
  }

  async function onSubmit(values: BlogPostValues) {
    const body = {
      title: values.title,
      slug: values.slug || null,
      excerpt: values.excerpt || null,
      body: values.body,
      category_id: values.categoryId ? Number(values.categoryId) : null,
      tags: parseTags(values.tags),
      status: values.status,
      published_at: fromLocalInput(values.publishedAt),
      cover: values.cover || null,
      meta_title: values.metaTitle || null,
      meta_description: values.metaDescription || null,
    }

    try {
      const result = await api<{ id: number; slug: string }>(
        initial ? `/system/blog/posts/${initial.id}` : '/system/blog/posts',
        { method: initial ? 'PUT' : 'POST', body },
      )

      toastSuccess(values.status === 'published' ? 'مقاله منتشر شد.' : 'پیش‌نویس ذخیره شد.')
      setSavedSlug(result.slug)
      reset({ ...values, slug: result.slug })
      await queryClient.invalidateQueries({ queryKey: queryKeys.blog.all() })

      if (!initial) void navigate(`/system/blog/${result.id}`, { replace: true })
    } catch (error) {
      if (error instanceof ApiError) {
        const fields: Array<[keyof BlogPostValues, string]> = [
          ['title', 'title'],
          ['slug', 'slug'],
          ['excerpt', 'excerpt'],
          ['body', 'body'],
          ['publishedAt', 'published_at'],
          ['metaDescription', 'meta_description'],
        ]
        let mapped = false
        for (const [field, key] of fields) {
          const message = error.fieldError(key)
          if (message) {
            setError(field, { message })
            mapped = true
          }
        }
        if (mapped) return
      }
      alertError(error, 'ذخیره‌ی مقاله ممکن نشد.')
    }
  }

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-5">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <Link
          to="/system/blog"
          className="flex items-center gap-1 text-small"
          style={{ color: 'var(--text-secondary)' }}
        >
          <ArrowRight size={16} /> همه‌ی مقاله‌ها
        </Link>
        <div className="flex items-center gap-2">
          {savedSlug && (
            <a
              href={`/blog/${encodeURIComponent(savedSlug)}`}
              target="_blank"
              rel="noopener noreferrer"
              className="flex items-center gap-1 rounded-xl border px-3 py-2 text-small"
              style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
            >
              <ExternalLink size={14} /> پیش‌نمایش
            </a>
          )}
          <button
            type="submit"
            disabled={isSubmitting}
            className="flex items-center gap-2 rounded-xl px-4 py-2 text-small font-semibold text-white disabled:opacity-50"
            style={{ backgroundColor: 'var(--color-brand-500)' }}
          >
            {isSubmitting && <Loader2 size={16} className="animate-spin" />}
            ذخیره
          </button>
        </div>
      </header>

      <div className="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <Card>
          <div className="flex flex-col gap-4">
            <TextField label="عنوان" {...register('title')} error={errors.title?.message} />
            <TextAreaField
              label="خلاصه (زیرِ عنوان در فهرست و نتیجه‌ی جست‌وجو)"
              rows={2}
              {...register('excerpt')}
              error={errors.excerpt?.message}
            />

            <div className="flex items-center justify-between gap-2">
              <span className="text-small" style={{ color: 'var(--text-tertiary)' }}>
                مارک‌داون: ## عنوانِ بخش، **پررنگ**، [پیوند](https://…)، جدول. HTML پذیرفته نمی‌شود.
              </span>
              <label
                className="flex cursor-pointer items-center gap-1 rounded-lg border px-2.5 py-1 text-small"
                style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
              >
                {uploading === 'body' ? (
                  <Loader2 size={14} className="animate-spin" />
                ) : (
                  <ImagePlus size={14} />
                )}
                افزودنِ تصویر
                <input
                  type="file"
                  accept="image/jpeg,image/png,image/webp"
                  className="sr-only"
                  onChange={(event) => onFile(event, 'body')}
                  disabled={uploading !== null}
                />
              </label>
            </div>

            <TextAreaField
              label="متنِ مقاله"
              rows={22}
              dir="rtl"
              {...bodyField}
              ref={(element) => {
                bodyField.ref(element)
                bodyRef.current = element
              }}
              error={errors.body?.message}
            />
          </div>
        </Card>

        <div className="flex flex-col gap-5">
          <Card title="انتشار">
            <div className="flex flex-col gap-4">
              <SelectField
                label="وضعیت"
                options={[
                  { value: 'draft', label: 'پیش‌نویس' },
                  { value: 'published', label: 'منتشرشده' },
                ]}
                {...register('status')}
              />
              <TextField
                label="زمانِ انتشار (خالی = همین حالا)"
                type="datetime-local"
                {...register('publishedAt')}
                error={errors.publishedAt?.message}
              />
              <SelectField
                label="دسته"
                placeholder="بدونِ دسته"
                options={categories.map((c) => ({ value: String(c.id), label: c.name }))}
                {...register('categoryId')}
              />
              <TextField
                label="برچسب‌ها (با «،» جدا کنید)"
                placeholder="شارژ، حسابداری"
                {...register('tags')}
              />
            </div>
          </Card>

          <Card title="تصویرِ جلد">
            {cover && (
              <img
                src={`/blog/media/${cover}`}
                alt="جلدِ مقاله"
                width={640}
                height={360}
                loading="lazy"
                className="mb-3 aspect-video w-full rounded-xl object-cover"
              />
            )}
            <div className="flex gap-2">
              <label
                className="flex cursor-pointer items-center gap-1 rounded-lg border px-2.5 py-1 text-small"
                style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
              >
                {uploading === 'cover' ? (
                  <Loader2 size={14} className="animate-spin" />
                ) : (
                  <ImagePlus size={14} />
                )}
                {cover ? 'تعویض' : 'انتخابِ تصویر'}
                <input
                  type="file"
                  accept="image/jpeg,image/png,image/webp"
                  className="sr-only"
                  onChange={(event) => onFile(event, 'cover')}
                  disabled={uploading !== null}
                />
              </label>
              {cover && (
                <button
                  type="button"
                  onClick={() => setValue('cover', '', { shouldDirty: true })}
                  className="rounded-lg border px-2.5 py-1 text-small"
                  style={{ borderColor: 'var(--border-subtle)', color: 'var(--color-danger)' }}
                >
                  حذفِ جلد
                </button>
              )}
            </div>
          </Card>

          <Card title="سئو">
            <div className="flex flex-col gap-4">
              <TextField
                label="نامک (آدرس؛ خالی = از عنوان)"
                dir="rtl"
                {...register('slug')}
                error={errors.slug?.message}
              />
              <TextField label="عنوانِ سئو (اختیاری)" {...register('metaTitle')} />
              <TextAreaField
                label="توضیحِ سئو (اختیاری، تا ۳۰۰ نویسه)"
                rows={3}
                {...register('metaDescription')}
                error={errors.metaDescription?.message}
              />
            </div>
          </Card>
        </div>
      </div>
    </form>
  )
}
