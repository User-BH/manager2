import { useEffect, useRef, useState } from 'react'
import { useForm, Controller } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { ImageUp, Loader2 } from 'lucide-react'
import { Modal } from '@/components/ui/Modal'
import { CheckField, TextField } from '@/components/ui/Field'
import { JalaliDatePicker } from '@/components/ui/JalaliDatePicker'
import { api, ApiError } from '@/lib/api'
import { toastSuccess } from '@/lib/alert'
import { adSchema, emptyAd, type AdFormValues, type AdItem } from './schema'

const MAX_IMAGE_BYTES = 3 * 1024 * 1024
const ACCEPTED = ['image/jpeg', 'image/png', 'image/webp']

/** نسبت پیشنهادی بنر، همان نسبتی که قاب اسلایدر دارد. */
const RECOMMENDED = '۱۶۰۰×۵۲۰ پیکسل'

export function AdFormModal({
  open,
  editing,
  onClose,
  onSaved,
}: {
  open: boolean
  /** null یعنی ساخت بنر تازه. */
  editing: AdItem | null
  onClose: () => void
  onSaved: () => void
}) {
  const [file, setFile] = useState<File | null>(null)
  const [preview, setPreview] = useState<string | null>(null)
  const [imageError, setImageError] = useState<string | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const fileInput = useRef<HTMLInputElement>(null)

  const {
    register,
    control,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<AdFormValues>({ resolver: zodResolver(adSchema), defaultValues: emptyAd })

  // هر بار باز شدن مودال، فرم را با داده‌ی بنرِ در دست ویرایش پر می‌کند
  useEffect(() => {
    if (!open) return

    setFile(null)
    setImageError(null)
    setFormError(null)

    reset(
      editing
        ? {
            title: editing.title,
            subtitle: editing.subtitle ?? '',
            href: editing.href,
            sortOrder: editing.sortOrder,
            isActive: editing.isActive,
            startsAt: editing.startsAt ?? '',
            endsAt: editing.endsAt ?? '',
          }
        : emptyAd,
    )
  }, [open, editing, reset])

  // آدرس شیء (blob) باید بعد از استفاده آزاد شود وگرنه نشت حافظه دارد
  useEffect(() => {
    if (!file) {
      setPreview(null)
      return
    }

    const url = URL.createObjectURL(file)
    setPreview(url)

    return () => URL.revokeObjectURL(url)
  }, [file])

  function pickFile(selected: File | undefined) {
    if (!selected) return

    if (!ACCEPTED.includes(selected.type)) {
      setImageError('فقط تصویر JPG، PNG یا WebP پذیرفته می‌شود.')
      return
    }
    if (selected.size > MAX_IMAGE_BYTES) {
      setImageError('حجم تصویر نباید از ۳ مگابایت بیشتر باشد.')
      return
    }

    setImageError(null)
    setFile(selected)
  }

  async function onSubmit(values: AdFormValues) {
    setFormError(null)

    // ساخت بنر تازه بدون تصویر ممکن نیست؛ در ویرایش، نبودِ فایل یعنی
    // تصویر فعلی بماند.
    if (!editing && !file) {
      setImageError('انتخاب تصویر بنر الزامی است.')
      return
    }

    const body = new FormData()
    body.append('title', values.title)
    body.append('subtitle', values.subtitle ?? '')
    body.append('href', values.href)
    body.append('sort_order', String(values.sortOrder))
    // چک‌باکس خاموش را هم صریح می‌فرستیم تا سرور بتواند غیرفعالش کند
    body.append('is_active', values.isActive ? '1' : '0')
    if (values.startsAt) body.append('starts_at', values.startsAt)
    if (values.endsAt) body.append('ends_at', values.endsAt)
    if (file) body.append('image', file)

    try {
      const path = editing ? `/system/ads/${editing.id}` : '/system/ads'
      const result = await api<{ message: string }>(path, { method: 'POST', body })

      toastSuccess(result.message)
      onSaved()
      onClose()
    } catch (err) {
      if (err instanceof ApiError && Object.keys(err.errors).length) {
        // خطاهای فیلدی سرور را زیر همان ورودی می‌نشانیم
        const map: Record<string, keyof AdFormValues> = {
          title: 'title',
          subtitle: 'subtitle',
          href: 'href',
          sort_order: 'sortOrder',
          starts_at: 'startsAt',
          ends_at: 'endsAt',
        }

        for (const [field, messages] of Object.entries(err.errors)) {
          if (field === 'image') setImageError(messages[0])
          else if (map[field]) setError(map[field], { message: messages[0] })
        }
        return
      }

      setFormError(err instanceof ApiError ? err.message : 'ذخیره‌ی بنر ممکن نشد.')
    }
  }

  const shownPreview = preview ?? editing?.image ?? null

  return (
    <Modal open={open} title={editing ? 'ویرایش بنر تبلیغاتی' : 'بنر تبلیغاتی تازه'} onClose={onClose}>
      <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4">
        <TextField
          label="عنوان"
          placeholder="مثلاً: نیترو پنل — میزبانی و سرور ابری"
          error={errors.title?.message}
          {...register('title')}
        />

        <TextField
          label="توضیح کوتاه"
          placeholder="یک جمله درباره‌ی این تبلیغ"
          error={errors.subtitle?.message}
          {...register('subtitle')}
        />

        <TextField
          label="لینک مقصد"
          placeholder="https://example.com"
          dir="ltr"
          className="text-left"
          error={errors.href?.message}
          {...register('href')}
        />

        {/* --- تصویر --- */}
        <div className="flex flex-col gap-1.5">
          <label className="text-[13px] font-medium" style={{ color: 'var(--text-secondary)' }}>
            تصویر بنر
          </label>

          <button
            type="button"
            onClick={() => fileInput.current?.click()}
            className="group relative flex h-32 w-full items-center justify-center overflow-hidden rounded-xl border border-dashed transition-colors"
            style={{
              borderColor: imageError ? 'var(--color-danger)' : 'var(--border-subtle)',
              backgroundColor: 'var(--surface-sunken)',
            }}
          >
            {shownPreview ? (
              <>
                <img src={shownPreview} alt="" className="h-full w-full object-cover" />
                <span className="absolute inset-0 flex items-center justify-center bg-black/45 text-xs font-semibold text-white opacity-0 transition-opacity group-hover:opacity-100">
                  تغییر تصویر
                </span>
              </>
            ) : (
              <span
                className="flex flex-col items-center gap-1.5 text-xs"
                style={{ color: 'var(--text-secondary)' }}
              >
                <ImageUp size={22} />
                انتخاب تصویر
              </span>
            )}
          </button>

          <input
            ref={fileInput}
            type="file"
            accept={ACCEPTED.join(',')}
            className="hidden"
            onChange={(e) => pickFile(e.target.files?.[0])}
          />

          <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
            نسبت پیشنهادی {RECOMMENDED} — JPG یا PNG یا WebP، حداکثر ۳ مگابایت.
          </p>

          {imageError && (
            <p className="text-xs" style={{ color: 'var(--color-danger)' }}>
              {imageError}
            </p>
          )}
        </div>

        {/* --- بازه‌ی نمایش --- */}
        <div className="grid gap-4 sm:grid-cols-2">
          <Controller
            name="startsAt"
            control={control}
            render={({ field }) => (
              <JalaliDatePicker
                label="شروع نمایش (اختیاری)"
                value={field.value}
                onChange={field.onChange}
                error={errors.startsAt?.message}
                placeholder="بدون محدودیت"
              />
            )}
          />
          <Controller
            name="endsAt"
            control={control}
            render={({ field }) => (
              <JalaliDatePicker
                label="پایان نمایش (اختیاری)"
                value={field.value}
                onChange={field.onChange}
                error={errors.endsAt?.message}
                placeholder="بدون محدودیت"
              />
            )}
          />
        </div>

        <TextField
          label="ترتیب نمایش"
          type="number"
          min={0}
          max={999}
          error={errors.sortOrder?.message}
          {...register('sortOrder', { valueAsNumber: true })}
        />

        <CheckField label="فعال باشد (روی صفحه‌ی اصلی دیده شود)" {...register('isActive')} />

        {formError && (
          <p className="text-xs" style={{ color: 'var(--color-danger)' }}>
            {formError}
          </p>
        )}

        <div className="flex justify-end gap-2 pt-1">
          <button
            type="button"
            onClick={onClose}
            className="rounded-xl border px-4 py-2 text-[13px] font-semibold transition-colors hover:bg-(--surface-sunken)"
            style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
          >
            انصراف
          </button>
          <button
            type="submit"
            disabled={isSubmitting}
            className="flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-semibold text-white transition-opacity disabled:opacity-60"
            style={{ backgroundColor: 'var(--color-primary)' }}
          >
            {isSubmitting && <Loader2 size={15} className="animate-spin" />}
            ذخیره
          </button>
        </div>
      </form>
    </Modal>
  )
}
