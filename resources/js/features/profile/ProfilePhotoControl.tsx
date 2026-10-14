import { useRef } from 'react'
import { Pencil, Trash2 } from 'lucide-react'
import { api } from '@/shared/lib/api'
import { useAction } from '@/shared/hooks/useAction'
import { useAuthStore } from '@/shared/stores/authStore'

/**
 * گذاشتن و برداشتنِ تصویرِ پروفایل (مرحلهٔ ۱۰ · فاز ۷؛ بندِ ۵).
 *
 * فایل همان‌طور که هست فرستاده می‌شود؛ سرور بازش می‌کند، مربعِ وسط را برمی‌دارد و بی metadata ذخیره می‌کند
 * (`ProfilePhoto`). پس از هر تغییر `/me` دوباره خوانده می‌شود تا منوی کاربر و پیام‌رسان همان تصویرِ تازه را ببینند.
 */
export function ProfilePhotoControl({
  hasPhoto,
  onChanged,
}: {
  hasPhoto: boolean
  onChanged: () => void
}) {
  const input = useRef<HTMLInputElement>(null)
  const { run, pendingKey } = useAction()
  const refresh = useAuthStore((state) => state.refresh)

  const after = () => {
    onChanged()
    void refresh()
  }

  function upload(file: File) {
    const form = new FormData()
    form.append('avatar', file)

    return run(() => api('/profile/avatar', { method: 'POST', body: form }), {
      key: 'upload',
      success: 'تصویرِ پروفایل ذخیره شد.',
      onDone: after,
    })
  }

  return (
    <div className="flex items-center gap-2">
      <input
        ref={input}
        type="file"
        accept="image/jpeg,image/png,image/webp"
        className="hidden"
        aria-label="انتخابِ تصویرِ پروفایل"
        onChange={(event) => {
          const file = event.target.files?.[0]
          event.target.value = ''
          if (file) void upload(file)
        }}
      />
      <button
        type="button"
        disabled={pendingKey !== null}
        onClick={() => input.current?.click()}
        className="flex items-center gap-1.5 rounded-xl border px-3 py-2 text-xs font-semibold disabled:opacity-60"
        style={{ borderColor: 'var(--border-default)', color: 'var(--text-secondary)' }}
      >
        <Pencil size={14} />
        {hasPhoto ? 'تغییرِ تصویر' : 'گذاشتنِ تصویر'}
      </button>
      {hasPhoto && (
        <button
          type="button"
          disabled={pendingKey !== null}
          onClick={() =>
            void run(() => api('/profile/avatar', { method: 'DELETE' }), {
              key: 'remove',
              confirm: {
                title: 'تصویرِ پروفایل برداشته شود؟',
                text: 'همه‌جا — منو، پیام‌رسان و فهرست‌ها — به‌جای آن حرفِ اولِ نامتان نشان داده می‌شود.',
                confirmLabel: 'بردار',
                danger: true,
              },
              success: 'تصویرِ پروفایل برداشته شد.',
              onDone: after,
            })
          }
          className="flex items-center gap-1.5 rounded-xl border px-3 py-2 text-xs font-semibold disabled:opacity-60"
          style={{ borderColor: 'var(--border-default)', color: 'var(--color-danger)' }}
        >
          <Trash2 size={14} />
          برداشتن
        </button>
      )}
    </div>
  )
}
