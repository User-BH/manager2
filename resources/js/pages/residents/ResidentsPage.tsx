import { useCallback, useState } from 'react'
import { motion } from 'framer-motion'
import {
  Plus, Pencil, Trash2, UserRound, ToggleLeft, ToggleRight,
  MessageSquare, MessageSquareOff,
} from 'lucide-react'
import { Card } from '@/components/ui/Card'
import { Modal } from '@/components/ui/Modal'
import { SearchInput } from '@/components/ui/SearchInput'
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/PageState'
import { ResidentForm } from './ResidentForm'
import { useApi } from '@/hooks/useApi'
import { useDocumentTitle } from '@/hooks'
import { api } from '@/lib/api'
import { alertError, confirmAction, toastSuccess } from '@/lib/alert'
import { formatNumber } from '@/lib/format'
import type { Resident, ResidentsResponse } from './types'

export function ResidentsPage() {
  const [search, setSearch] = useState('')
  const [editing, setEditing] = useState<Resident | null>(null)
  const [creating, setCreating] = useState(false)

  useDocumentTitle('ساکنین')

  const query = search ? `/residents?search=${encodeURIComponent(search)}` : '/residents'
  const { data, error, isLoading, reload } = useApi<ResidentsResponse>(query)

  const handleSearch = useCallback((value: string) => setSearch(value), [])

  async function handleDelete(resident: Resident) {
    const ok = await confirmAction({
      title: `${resident.name} حذف شود؟`,
      text: 'دسترسی این ساکن به سامانه بسته می‌شود.',
      confirmLabel: 'حذف کن',
      danger: true,
    })
    if (!ok) return

    try {
      await api(`/residents/${resident.id}`, { method: 'DELETE' })
      toastSuccess('ساکن حذف شد.')
      reload()
    } catch (error) {
      alertError(error, 'حذف ساکن ممکن نشد.')
    }
  }

  async function handleToggle(resident: Resident) {
    try {
      await api(`/residents/${resident.id}/toggle-active`, { method: 'PATCH' })
      reload()
    } catch (error) {
      alertError(error, 'تغییر وضعیت ساکن ممکن نشد.')
    }
  }

  /** بستن/بازکردن اجازه‌ی ارسال پیام در پیام‌رسان. */
  async function handleToggleMessaging(resident: Resident) {
    try {
      const { message } = await api<{ message: string }>(
        `/residents/${resident.id}/toggle-messaging`,
        { method: 'PATCH' },
      )
      toastSuccess(message)
      reload()
    } catch (error) {
      alertError(error, 'تغییر دسترسی پیام‌رسان ممکن نشد.')
    }
  }

  function handleSaved() {
    setEditing(null)
    setCreating(false)
    reload()
  }

  return (
    <div className="flex flex-col gap-5">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
            ساکنین
          </h1>
          <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
            {data ? `${formatNumber(data.meta.total)} ساکن ثبت شده` : 'در حال بارگذاری…'}
          </p>
        </div>

        <div className="flex flex-1 flex-wrap items-center justify-end gap-2">
          <SearchInput onSearch={handleSearch} placeholder="جستجوی نام یا شماره…" />
          <button
            onClick={() => setCreating(true)}
            className="flex items-center gap-1.5 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white transition-transform hover:scale-[1.03]"
            style={{ backgroundColor: 'var(--color-brand-500)' }}
          >
            <Plus size={16} />
            ساکن جدید
          </button>
        </div>
      </header>

      {isLoading && <LoadingState rows={5} />}
      {error && <ErrorState message={error} onRetry={reload} />}

      {data && !isLoading && (
        <Card>
          {data.data.length === 0 ? (
            <EmptyState
              message={search ? 'ساکنی با این مشخصات پیدا نشد.' : 'هنوز ساکنی ثبت نشده است.'}
              hint={search ? undefined : 'با دکمه‌ی «ساکن جدید» شروع کنید.'}
            />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[680px] text-right text-[13px]">
                <thead>
                  <tr style={{ color: 'var(--text-tertiary)' }}>
                    <th className="pb-3 font-medium">نام</th>
                    <th className="pb-3 font-medium">شماره تماس</th>
                    <th className="pb-3 font-medium">نقش</th>
                    <th className="pb-3 font-medium">واحد</th>
                    <th className="pb-3 font-medium">وضعیت</th>
                    <th className="pb-3 text-left font-medium">عملیات</th>
                  </tr>
                </thead>
                <tbody>
                  {data.data.map((resident, index) => (
                    <motion.tr
                      key={resident.id}
                      initial={{ opacity: 0, y: 6 }}
                      animate={{ opacity: 1, y: 0 }}
                      transition={{ duration: 0.25, delay: Math.min(index * 0.02, 0.3) }}
                      className="border-t"
                      style={{ borderColor: 'var(--border-subtle)' }}
                    >
                      <td className="py-3 font-semibold" style={{ color: 'var(--text-primary)' }}>
                        <span className="flex items-center gap-2">
                          <span
                            className="flex h-7 w-7 items-center justify-center rounded-full text-white"
                            style={{ backgroundColor: 'var(--color-brand-500)' }}
                          >
                            <UserRound size={14} />
                          </span>
                          {resident.name}
                        </span>
                      </td>
                      <td className="py-3 tabular-nums" dir="ltr" style={{ color: 'var(--text-secondary)' }}>
                        {resident.phone}
                      </td>
                      <td className="py-3" style={{ color: 'var(--text-secondary)' }}>
                        {resident.roleLabel}
                      </td>
                      <td className="py-3" style={{ color: 'var(--text-secondary)' }}>
                        {resident.units.length > 0
                          ? resident.units.map((u) => u.label).join('، ')
                          : '—'}
                      </td>
                      <td className="py-3">
                        <span
                          className="rounded-full px-2.5 py-0.5 text-[11px] font-medium"
                          style={
                            resident.isActive
                              ? {
                                  backgroundColor: 'color-mix(in srgb, var(--state-success) 15%, transparent)',
                                  color: 'var(--state-success)',
                                }
                              : {
                                  backgroundColor: 'color-mix(in srgb, var(--color-danger) 13%, transparent)',
                                  color: 'var(--color-danger)',
                                }
                          }
                        >
                          {resident.isActive ? 'فعال' : 'غیرفعال'}
                        </span>
                      </td>
                      <td className="py-3">
                        <div className="flex items-center justify-end gap-1">
                          <button
                            onClick={() => handleToggle(resident)}
                            aria-label={resident.isActive ? 'غیرفعال کردن' : 'فعال کردن'}
                            title={resident.isActive ? 'غیرفعال کردن' : 'فعال کردن'}
                            className="flex h-8 w-8 items-center justify-center rounded-lg transition-colors hover:bg-(--surface-sunken)"
                            style={{ color: resident.isActive ? 'var(--state-success)' : 'var(--text-tertiary)' }}
                          >
                            {resident.isActive ? <ToggleRight size={17} /> : <ToggleLeft size={17} />}
                          </button>

                          {/* محدودیت پیام‌رسان — سرور همین پرچم را هنگام ارسال پیام بررسی می‌کند */}
                          <button
                            onClick={() => handleToggleMessaging(resident)}
                            aria-label={resident.canMessage ? 'بستن پیام‌رسان' : 'باز کردن پیام‌رسان'}
                            title={
                              resident.canMessage
                                ? 'بستن ارسال پیام برای این ساکن'
                                : 'اجازه‌ی ارسال پیام به این ساکن'
                            }
                            className="flex h-8 w-8 items-center justify-center rounded-lg transition-colors hover:bg-(--surface-sunken)"
                            style={{ color: resident.canMessage ? 'var(--text-secondary)' : 'var(--color-danger)' }}
                          >
                            {resident.canMessage ? <MessageSquare size={15} /> : <MessageSquareOff size={15} />}
                          </button>
                          <button
                            onClick={() => setEditing(resident)}
                            aria-label={`ویرایش ${resident.name}`}
                            className="flex h-8 w-8 items-center justify-center rounded-lg transition-colors hover:bg-(--surface-sunken)"
                            style={{ color: 'var(--text-secondary)' }}
                          >
                            <Pencil size={15} />
                          </button>
                          <button
                            onClick={() => handleDelete(resident)}
                            aria-label={`حذف ${resident.name}`}
                            className="flex h-8 w-8 items-center justify-center rounded-lg transition-colors hover:bg-(--surface-sunken)"
                            style={{ color: 'var(--color-danger)' }}
                          >
                            <Trash2 size={15} />
                          </button>
                        </div>
                      </td>
                    </motion.tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}

      <Modal
        open={creating || editing !== null}
        title={editing ? `ویرایش ${editing.name}` : 'ساکن جدید'}
        onClose={() => {
          setCreating(false)
          setEditing(null)
        }}
      >
        {data && (
          <ResidentForm
            resident={editing}
            filters={data.filters}
            onSaved={handleSaved}
            onCancel={() => {
              setCreating(false)
              setEditing(null)
            }}
          />
        )}
      </Modal>
    </div>
  )
}
