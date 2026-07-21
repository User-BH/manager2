import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { motion } from 'framer-motion'
import { Plus, Pin, Trash2, Megaphone, Loader2, Save, AlertCircle } from 'lucide-react'
import { z } from 'zod'
import { Card } from '@/components/ui/Card'
import { Modal } from '@/components/ui/Modal'
import { CheckField, SelectField, TextField } from '@/components/ui/Field'
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/PageState'
import { useApi } from '@/hooks/useApi'
import { useDocumentTitle } from '@/hooks'
import { api, ApiError } from '@/lib/api'
import { formatNumber } from '@/lib/format'

const announcementSchema = z.object({
  title: z.string().min(1, 'عنوان را وارد کنید').max(150, 'عنوان طولانی است'),
  body: z.string().min(1, 'متن اطلاعیه را وارد کنید').max(5000, 'متن بیش از حد طولانی است'),
  audience: z.string().min(1, 'مخاطب را انتخاب کنید'),
  is_pinned: z.boolean().optional(),
})

type AnnouncementFormValues = z.infer<typeof announcementSchema>

interface Announcement {
  id: number
  title: string
  body: string
  audience: string
  audienceLabel: string
  isPinned: boolean
  isActive: boolean
  publishedAt: string | null
}

interface AnnouncementsResponse {
  data: Announcement[]
  meta: { currentPage: number; lastPage: number; total: number }
  canManage: boolean
  audienceOptions: { value: string; label: string }[]
}

export function AnnouncementsPage() {
  const [creating, setCreating] = useState(false)

  useDocumentTitle('اطلاعیه‌ها')

  const { data, error, isLoading, reload } = useApi<AnnouncementsResponse>('/announcements')

  async function handleDelete(announcement: Announcement) {
    if (!confirm(`اطلاعیه «${announcement.title}» حذف شود؟`)) return

    await api(`/announcements/${announcement.id}`, { method: 'DELETE' })
    reload()
  }

  return (
    <div className="flex flex-col gap-5">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
            اطلاعیه‌ها
          </h1>
          <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
            {data ? `${formatNumber(data.meta.total)} اطلاعیه` : 'در حال بارگذاری…'}
          </p>
        </div>

        {data?.canManage && (
          <button
            onClick={() => setCreating(true)}
            className="flex items-center gap-1.5 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white transition-transform hover:scale-[1.03]"
            style={{ backgroundColor: 'var(--color-brand-500)' }}
          >
            <Plus size={16} />
            اطلاعیه جدید
          </button>
        )}
      </header>

      {isLoading && <LoadingState rows={4} />}
      {error && <ErrorState message={error} onRetry={reload} />}

      {data && !isLoading && (
        <>
          {data.data.length === 0 ? (
            <Card>
              <EmptyState
                message="اطلاعیه‌ای منتشر نشده است."
                hint={data.canManage ? 'با دکمه‌ی «اطلاعیه جدید» اولین اطلاعیه را بنویسید.' : undefined}
              />
            </Card>
          ) : (
            <div className="flex flex-col gap-3">
              {data.data.map((announcement, index) => (
                <motion.article
                  key={announcement.id}
                  initial={{ opacity: 0, y: 10 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ duration: 0.3, delay: Math.min(index * 0.04, 0.3) }}
                  className="rounded-2xl border p-5"
                  style={{
                    borderColor: announcement.isPinned ? 'var(--color-brand-300)' : 'var(--border-subtle)',
                    backgroundColor: 'var(--surface-base)',
                  }}
                >
                  <div className="flex items-start justify-between gap-3">
                    <div className="flex items-start gap-2.5">
                      <span
                        className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl"
                        style={{
                          backgroundColor: 'color-mix(in srgb, var(--color-brand-500) 13%, transparent)',
                          color: 'var(--color-brand-600)',
                        }}
                      >
                        <Megaphone size={17} />
                      </span>

                      <div>
                        <h2
                          className="flex items-center gap-2 text-[15px] font-bold"
                          style={{ color: 'var(--text-primary)' }}
                        >
                          {announcement.title}
                          {announcement.isPinned && (
                            <span
                              className="flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium"
                              style={{
                                backgroundColor: 'color-mix(in srgb, var(--color-accent-500) 16%, transparent)',
                                color: 'var(--color-accent-600)',
                              }}
                            >
                              <Pin size={10} />
                              سنجاق‌شده
                            </span>
                          )}
                        </h2>

                        <p className="mt-0.5 text-[11px]" style={{ color: 'var(--text-tertiary)' }}>
                          {announcement.publishedAt} · {announcement.audienceLabel}
                          {!announcement.isActive && ' · غیرفعال'}
                        </p>
                      </div>
                    </div>

                    {data.canManage && (
                      <button
                        onClick={() => handleDelete(announcement)}
                        aria-label={`حذف ${announcement.title}`}
                        className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition-colors hover:bg-(--surface-sunken)"
                        style={{ color: 'var(--color-danger)' }}
                      >
                        <Trash2 size={15} />
                      </button>
                    )}
                  </div>

                  <p
                    className="mt-3 whitespace-pre-line text-[13.5px] leading-7"
                    style={{ color: 'var(--text-secondary)' }}
                  >
                    {announcement.body}
                  </p>
                </motion.article>
              ))}
            </div>
          )}
        </>
      )}

      <Modal open={creating} title="اطلاعیه جدید" onClose={() => setCreating(false)}>
        {data && (
          <AnnouncementForm
            audienceOptions={data.audienceOptions}
            onSaved={() => {
              setCreating(false)
              reload()
            }}
            onCancel={() => setCreating(false)}
          />
        )}
      </Modal>
    </div>
  )
}

function AnnouncementForm({
  audienceOptions,
  onSaved,
  onCancel,
}: {
  audienceOptions: { value: string; label: string }[]
  onSaved: () => void
  onCancel: () => void
}) {
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<AnnouncementFormValues>({
    resolver: zodResolver(announcementSchema),
    defaultValues: { title: '', body: '', audience: audienceOptions[0]?.value ?? 'all', is_pinned: false },
  })

  async function onSubmit(values: AnnouncementFormValues) {
    setFormError(null)

    try {
      await api('/announcements', { method: 'POST', body: values })
      onSaved()
    } catch (err) {
      if (err instanceof ApiError) {
        let handled = false
        for (const [field, messages] of Object.entries(err.errors)) {
          setError(field as keyof AnnouncementFormValues, { message: messages[0] })
          handled = true
        }
        if (!handled) setFormError(err.message)
      } else {
        setFormError('ارتباط با سرور برقرار نشد.')
      }
    }
  }

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4">
      {formError && (
        <div
          className="flex items-center gap-2 rounded-xl px-3.5 py-2.5 text-xs"
          style={{
            backgroundColor: 'color-mix(in srgb, var(--color-danger) 12%, transparent)',
            color: 'var(--color-danger)',
          }}
        >
          <AlertCircle size={15} className="shrink-0" />
          {formError}
        </div>
      )}

      <TextField label="عنوان" error={errors.title?.message} {...register('title')} />

      <div className="flex flex-col gap-1.5">
        <label className="text-[13px] font-medium" style={{ color: 'var(--text-secondary)' }}>
          متن اطلاعیه
        </label>
        <textarea
          rows={6}
          className="w-full resize-none rounded-xl border px-3 py-2.5 text-[13.5px] outline-none transition-all focus:ring-2"
          style={{
            backgroundColor: 'var(--surface-sunken)',
            borderColor: errors.body ? 'var(--color-danger)' : 'var(--border-subtle)',
            color: 'var(--text-primary)',
            ['--tw-ring-color' as string]: 'var(--ring-focus)',
          }}
          {...register('body')}
        />
        {errors.body && (
          <p className="text-xs" style={{ color: 'var(--color-danger)' }}>
            {errors.body.message}
          </p>
        )}
      </div>

      <SelectField
        label="مخاطب"
        options={audienceOptions}
        error={errors.audience?.message}
        {...register('audience')}
      />

      <CheckField label="سنجاق شود (بالای فهرست بماند)" {...register('is_pinned')} />

      <div className="mt-2 flex items-center gap-2">
        <button
          type="submit"
          disabled={isSubmitting}
          className="flex flex-1 items-center justify-center gap-2 rounded-xl py-3 text-sm font-bold text-white disabled:opacity-70"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          {isSubmitting ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
          انتشار اطلاعیه
        </button>
        <button
          type="button"
          onClick={onCancel}
          className="rounded-xl border px-5 py-3 text-sm font-semibold"
          style={{ borderColor: 'var(--border-default)', color: 'var(--text-primary)' }}
        >
          انصراف
        </button>
      </div>
    </form>
  )
}
