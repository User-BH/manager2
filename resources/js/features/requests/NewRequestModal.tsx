import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Loader2, Paperclip, X } from 'lucide-react'
import { Modal } from '@/shared/ui/Modal'
import { api } from '@/shared/lib/api'
import { alertError, toastSuccess } from '@/shared/lib/alert'
import type { Option } from './types'

/** همان قیدِ `StoreServiceRequestRequest`؛ اینجا فقط تا کاربر پیش از آپلود بفهمد. */
const MAX_ATTACHMENT_BYTES = 4 * 1024 * 1024
const ACCEPTED_TYPES = '.jpg,.jpeg,.png,.webp,.pdf'

const schema = z.object({
  title: z.string().min(3, 'عنوان را کامل‌تر بنویسید').max(150, 'عنوان بیش از حد طولانی است'),
  description: z.string().min(10, 'شرح درخواست را کامل‌تر بنویسید').max(3000),
  category: z.string().min(1, 'دسته‌بندی را انتخاب کنید'),
  priority: z.enum(['normal', 'urgent']),
})

type FormValues = z.infer<typeof schema>

/**
 * ثبتِ درخواستِ تازه (R25).
 *
 * «بحرانی» اینجا نیست و عمداً هم نیست: اگر ساکن بتواند بالاترین فوریت را
 * بزند، همه همیشه همان را می‌زنند و درجه‌بندی بی‌اثر می‌شود. مدیر می‌تواند
 * بعداً بالا ببردش.
 */
export function NewRequestModal({
  categories,
  onClose,
  onCreated,
}: {
  categories: Option[]
  onClose: () => void
  onCreated: () => void
}) {
  const [attachment, setAttachment] = useState<File | null>(null)
  const [fileError, setFileError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { title: '', description: '', category: '', priority: 'normal' },
  })

  const inputStyle = {
    backgroundColor: 'var(--surface-sunken)',
    borderColor: 'var(--border-subtle)',
    color: 'var(--text-primary)',
  }

  async function onSubmit(values: FormValues) {
    /*
     * وقتی پیوست هست، درخواست باید multipart برود. `api` خودش FormData را
     * تشخیص می‌دهد و Content-Type را دست نمی‌زند.
     */
    let body: unknown = values

    if (attachment) {
      const form = new FormData()
      Object.entries(values).forEach(([key, value]) => form.append(key, value))
      form.append('attachment', attachment)
      body = form
    }

    try {
      await api('/service-requests', { method: 'POST', body })
      toastSuccess('درخواست شما ثبت شد.')
      onCreated()
    } catch (err) {
      alertError(err, 'ثبت درخواست ممکن نشد.')
    }
  }

  function pickFile(file: File | null) {
    if (file && file.size > MAX_ATTACHMENT_BYTES) {
      setFileError('حجم فایل باید کمتر از ۴ مگابایت باشد.')
      return
    }

    setFileError(null)
    setAttachment(file)
  }

  return (
    <Modal open title="درخواست جدید" onClose={onClose}>
      <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-3">
        <label
          className="flex flex-col gap-1 text-[12px]"
          style={{ color: 'var(--text-secondary)' }}
        >
          موضوع
          <input
            {...register('title')}
            placeholder="مثلاً: آسانسور بین طبقه ۳ و ۴ گیر می‌کند"
            className="rounded-lg border px-3 py-2 text-[13px] outline-none"
            style={inputStyle}
          />
          {errors.title && (
            <span style={{ color: 'var(--color-danger)' }}>{errors.title.message}</span>
          )}
        </label>

        <div className="flex gap-2">
          <label
            className="flex flex-1 flex-col gap-1 text-[12px]"
            style={{ color: 'var(--text-secondary)' }}
          >
            دسته‌بندی
            <select
              {...register('category')}
              className="rounded-lg border px-3 py-2 text-[13px] outline-none"
              style={inputStyle}
            >
              <option value="">انتخاب کنید…</option>
              {categories.map((category) => (
                <option key={category.value} value={category.value}>
                  {category.label}
                </option>
              ))}
            </select>
            {errors.category && (
              <span style={{ color: 'var(--color-danger)' }}>{errors.category.message}</span>
            )}
          </label>

          <label
            className="flex flex-1 flex-col gap-1 text-[12px]"
            style={{ color: 'var(--text-secondary)' }}
          >
            فوریت
            <select
              {...register('priority')}
              className="rounded-lg border px-3 py-2 text-[13px] outline-none"
              style={inputStyle}
            >
              <option value="normal">عادی</option>
              <option value="urgent">فوری</option>
            </select>
          </label>
        </div>

        <label
          className="flex flex-col gap-1 text-[12px]"
          style={{ color: 'var(--text-secondary)' }}
        >
          شرح
          <textarea
            {...register('description')}
            rows={5}
            placeholder="هرچه دقیق‌تر بنویسید، پیگیری سریع‌تر می‌شود."
            className="resize-none rounded-lg border px-3 py-2 text-[13px] outline-none"
            style={inputStyle}
          />
          {errors.description && (
            <span style={{ color: 'var(--color-danger)' }}>{errors.description.message}</span>
          )}
        </label>

        <div className="flex flex-col gap-1">
          {attachment ? (
            <div
              className="flex items-center gap-2 rounded-lg px-3 py-2 text-[12px]"
              style={{ backgroundColor: 'var(--surface-sunken)', color: 'var(--text-secondary)' }}
            >
              <Paperclip size={13} />
              <span className="flex-1 truncate">{attachment.name}</span>
              <button type="button" onClick={() => pickFile(null)} aria-label="حذف پیوست">
                <X size={14} />
              </button>
            </div>
          ) : (
            <label
              className="flex cursor-pointer items-center gap-1.5 text-[12px]"
              style={{ color: 'var(--color-brand-500)' }}
            >
              <Paperclip size={14} />
              افزودن عکس یا فایل (اختیاری)
              <input
                type="file"
                accept={ACCEPTED_TYPES}
                className="hidden"
                onChange={(event) => pickFile(event.target.files?.[0] ?? null)}
              />
            </label>
          )}

          {fileError && (
            <span className="text-[12px]" style={{ color: 'var(--color-danger)' }}>
              {fileError}
            </span>
          )}
        </div>

        <button
          type="submit"
          disabled={isSubmitting}
          className="mt-1 flex items-center justify-center gap-2 rounded-xl py-2.5 text-[13px] font-bold text-white disabled:opacity-60"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          {isSubmitting && <Loader2 size={15} className="animate-spin" />}
          ثبت درخواست
        </button>
      </form>
    </Modal>
  )
}
