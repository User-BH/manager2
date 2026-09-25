import { formatNumber } from '@/shared/lib/format'
import type { UploadHandle } from '@/shared/hooks/useUpload'

/**
 * نوارِ پیشرفتِ آپلود با دکمه‌ی لغو (مرحلهٔ ۵ · فاز ۶ · بندِ ۵۲).
 *
 * فقط وقتی آپلودی در جریان است رسم می‌شود. درصد برای صفحه‌خوان با
 * `role="progressbar"` اعلام می‌شود.
 */
export function UploadProgress({ upload }: { upload: Pick<UploadHandle, 'progress' | 'cancel'> }) {
  if (upload.progress === null) return null

  const percent = Math.round(upload.progress * 100)

  return (
    <div className="flex items-center gap-3">
      <div
        role="progressbar"
        aria-label="پیشرفتِ ارسالِ فایل"
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={percent}
        className="h-2 flex-1 overflow-hidden rounded-full"
        style={{ backgroundColor: 'var(--surface-sunken)' }}
      >
        <div
          className="h-full rounded-full transition-[width] duration-200"
          style={{ width: `${percent}%`, backgroundColor: 'var(--color-brand-500)' }}
        />
      </div>

      <span className="text-small tabular-nums" style={{ color: 'var(--text-secondary)' }}>
        {formatNumber(percent)}٪
      </span>

      <button
        type="button"
        onClick={upload.cancel}
        className="rounded-lg border px-2.5 py-1 text-small"
        style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
      >
        لغو
      </button>
    </div>
  )
}
