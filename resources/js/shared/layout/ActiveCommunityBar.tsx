import { Building2 } from 'lucide-react'
import { useAuth } from '@/shared/stores/authStore'

/**
 * نامِ کاملِ مجتمعِ فعال، بالای نوارِ اصلی (مرحلهٔ ۱۳ · فاز ۳؛ ایرادات ۱۰، بندهای ۴۳ و ۴۴).
 *
 * نام از پاسخِ سرور برای **زمینه‌ی همین نشست** است (`user.complex`)، نه از انتخابِ رابط. تعویض با ناوبریِ کامل است
 * (`switchContext`)، پس این نوار همیشه با نامِ مجتمعِ تازه از نو ساخته می‌شود — همان نشانه‌ی روشنِ «زمینه عوض شد».
 * بی مجتمع (سوپر ادمینی که مجتمعی برنگزیده) چیزی نشان نمی‌دهد.
 */
export function ActiveCommunityBar() {
  const { user } = useAuth()
  const complex = user?.complex

  if (!complex) return null

  return (
    <div
      role="note"
      aria-label="مجتمعِ فعال"
      className="flex h-8 shrink-0 items-center justify-center gap-2 border-b px-4 text-body"
      style={{
        backgroundColor: 'color-mix(in srgb, var(--color-brand-500) 9%, var(--surface-base))',
        borderColor: 'var(--border-subtle)',
      }}
    >
      <Building2
        size={14}
        aria-hidden="true"
        className="shrink-0"
        style={{ color: 'var(--color-brand-600)' }}
      />
      <span
        data-testid="active-community"
        className="min-w-0 truncate font-bold"
        style={{ color: 'var(--text-primary)' }}
        title={complex.name}
      >
        {complex.name}
      </span>
      {user.roleLabel && (
        <span className="shrink-0 text-xs" style={{ color: 'var(--text-tertiary)' }}>
          · {user.roleLabel}
        </span>
      )}
    </div>
  )
}
