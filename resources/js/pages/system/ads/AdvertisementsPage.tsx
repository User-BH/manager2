import { useState } from 'react'
import { motion } from 'framer-motion'
import { Eye, EyeOff, ExternalLink, Pencil, Plus, Trash2 } from 'lucide-react'
import { Card } from '@/components/ui/Card'
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/PageState'
import { useApi } from '@/hooks/useApi'
import { useDocumentTitle } from '@/hooks'
import { api } from '@/lib/api'
import { alertError, confirmAction, toastSuccess } from '@/lib/alert'
import { AdFormModal } from './AdFormModal'
import type { AdItem } from './schema'

/**
 * مدیریت بنرهای تبلیغاتی صفحه‌ی فرود.
 *
 * تا پیش از این، تبلیغات آرایه‌ای ثابت در کد فرانت‌اند بودند و هر تغییری —
 * حتی عوض کردن یک لینک — نیازمند بیلد و استقرار دوباره بود.
 */
export function AdvertisementsPage() {
  useDocumentTitle('تبلیغات صفحه اصلی')

  const { data, error, isLoading, reload } = useApi<{ ads: AdItem[] }>('/system/ads')
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<AdItem | null>(null)

  function openCreate() {
    setEditing(null)
    setFormOpen(true)
  }

  function openEdit(ad: AdItem) {
    setEditing(ad)
    setFormOpen(true)
  }

  async function toggle(ad: AdItem) {
    try {
      const result = await api<{ message: string }>(`/system/ads/${ad.id}/toggle`, { method: 'PATCH' })
      toastSuccess(result.message)
      reload()
    } catch (err) {
      alertError(err, 'تغییر وضعیت بنر ممکن نشد.')
    }
  }

  async function remove(ad: AdItem) {
    const confirmed = await confirmAction({
      title: 'حذف بنر تبلیغاتی',
      text: `«${ad.title}» حذف شود؟ تصویر آن هم از سرور پاک می‌شود.`,
      confirmLabel: 'حذف کن',
      danger: true,
    })
    if (!confirmed) return

    try {
      const result = await api<{ message: string }>(`/system/ads/${ad.id}`, { method: 'DELETE' })
      toastSuccess(result.message)
      reload()
    } catch (err) {
      alertError(err, 'حذف بنر ممکن نشد.')
    }
  }

  if (isLoading) return <LoadingState rows={3} />
  if (error) return <ErrorState message={error} onRetry={reload} />

  const ads = data?.ads ?? []
  const liveCount = ads.filter((ad) => ad.isLive).length

  return (
    <div className="flex flex-col gap-5">
      <Card
        title="تبلیغات صفحه اصلی"
        subtitle={`${ads.length} بنر ثبت شده — ${liveCount} بنر همین حالا روی سایت دیده می‌شود`}
        actions={
          <button
            onClick={openCreate}
            className="flex items-center gap-1.5 rounded-xl px-4 py-2 text-[13px] font-semibold text-white transition-opacity hover:opacity-90"
            style={{ backgroundColor: 'var(--color-primary)' }}
          >
            <Plus size={16} />
            بنر تازه
          </button>
        }
      >
        {ads.length === 0 ? (
          <EmptyState
            message="هنوز بنری ثبت نشده است."
            hint="تا وقتی بنری فعال نباشد، بخش تبلیغات در صفحه‌ی اصلی اصلاً نمایش داده نمی‌شود."
          />
        ) : (
          <ul className="flex flex-col gap-3">
            {ads.map((ad, index) => (
              <motion.li
                key={ad.id}
                initial={{ opacity: 0, y: 12 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: index * 0.04 }}
                className="flex flex-col gap-3 rounded-2xl border p-3 sm:flex-row sm:items-center"
                style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-sunken)' }}
              >
                {/* پیش‌نمایش تصویر با همان نسبتِ بنر واقعی */}
                <div
                  className="h-20 w-full shrink-0 overflow-hidden rounded-xl sm:w-48"
                  style={{ backgroundColor: 'var(--surface-2)' }}
                >
                  {ad.image && (
                    <img src={ad.image} alt="" className="h-full w-full object-cover" loading="lazy" />
                  )}
                </div>

                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <h3 className="text-[14px] font-bold" style={{ color: 'var(--text-primary)' }}>
                      {ad.title}
                    </h3>
                    <StatusBadge ad={ad} />
                  </div>

                  {ad.subtitle && (
                    <p className="mt-1 line-clamp-1 text-xs" style={{ color: 'var(--text-secondary)' }}>
                      {ad.subtitle}
                    </p>
                  )}

                  <div
                    className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px]"
                    style={{ color: 'var(--text-muted)' }}
                  >
                    <a
                      href={ad.href}
                      target="_blank"
                      rel="noopener noreferrer"
                      dir="ltr"
                      className="inline-flex max-w-full items-center gap-1 truncate hover:underline"
                    >
                      <ExternalLink size={11} className="shrink-0" />
                      {ad.href}
                    </a>

                    <span>ترتیب: {ad.sortOrder}</span>

                    {(ad.startsAtLabel || ad.endsAtLabel) && (
                      <span>
                        {ad.startsAtLabel ?? '—'} تا {ad.endsAtLabel ?? '—'}
                      </span>
                    )}
                  </div>
                </div>

                <div className="flex shrink-0 items-center gap-1">
                  <ActionButton
                    label={ad.isActive ? 'غیرفعال کردن' : 'فعال کردن'}
                    onClick={() => toggle(ad)}
                  >
                    {ad.isActive ? <EyeOff size={16} /> : <Eye size={16} />}
                  </ActionButton>

                  <ActionButton label="ویرایش" onClick={() => openEdit(ad)}>
                    <Pencil size={16} />
                  </ActionButton>

                  <ActionButton label="حذف" danger onClick={() => remove(ad)}>
                    <Trash2 size={16} />
                  </ActionButton>
                </div>
              </motion.li>
            ))}
          </ul>
        )}
      </Card>

      <AdFormModal
        open={formOpen}
        editing={editing}
        onClose={() => setFormOpen(false)}
        onSaved={reload}
      />
    </div>
  )
}

/**
 * «فعال» و «در حال نمایش» یکی نیستند: بنری که بازه‌ی تاریخش هنوز نرسیده یا
 * تمام شده، فعال است ولی روی سایت دیده نمی‌شود. جدا کردنشان جلوی این سردرگمی
 * را می‌گیرد که «چرا فعالش کردم ولی نیست؟».
 */
function StatusBadge({ ad }: { ad: AdItem }) {
  const [text, color] = ad.isLive
    ? ['در حال نمایش', 'var(--color-success)']
    : ad.isActive
      ? ['خارج از بازه‌ی زمانی', 'var(--color-warning)']
      : ['غیرفعال', 'var(--text-muted)']

  return (
    <span
      className="rounded-full px-2 py-0.5 text-[10px] font-semibold"
      style={{ backgroundColor: `color-mix(in srgb, ${color} 15%, transparent)`, color }}
    >
      {text}
    </span>
  )
}

function ActionButton({
  label,
  danger = false,
  onClick,
  children,
}: {
  label: string
  danger?: boolean
  onClick: () => void
  children: React.ReactNode
}) {
  return (
    <button
      onClick={onClick}
      title={label}
      aria-label={label}
      className="flex h-9 w-9 items-center justify-center rounded-xl border transition-colors hover:bg-(--surface-base)"
      style={{
        borderColor: 'var(--border-subtle)',
        color: danger ? 'var(--color-danger)' : 'var(--text-secondary)',
      }}
    >
      {children}
    </button>
  )
}
