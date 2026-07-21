import { useCallback, useState } from 'react'
import { motion } from 'framer-motion'
import { Plus, Pencil, Trash2, Building2, FileText } from 'lucide-react'
import { Card } from '@/components/ui/Card'
import { Modal } from '@/components/ui/Modal'
import { SearchInput } from '@/components/ui/SearchInput'
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/PageState'
import { UnitForm } from './UnitForm'
import { useApi } from '@/hooks/useApi'
import { useDocumentTitle } from '@/hooks'
import { api } from '@/lib/api'
import { formatMoney, formatNumber } from '@/lib/format'
import type { Unit, UnitsResponse } from './types'

export function UnitsPage() {
  const [search, setSearch] = useState('')
  const [editing, setEditing] = useState<Unit | null>(null)
  const [creating, setCreating] = useState(false)

  useDocumentTitle('واحدها')

  const query = search ? `/units?search=${encodeURIComponent(search)}` : '/units'
  const { data, error, isLoading, reload } = useApi<UnitsResponse>(query)

  // بدون useCallback، SearchInput هر رندر یک تابع تازه می‌گیرد و debounce ریست می‌شود
  const handleSearch = useCallback((value: string) => setSearch(value), [])

  async function handleDelete(unit: Unit) {
    if (!confirm(`واحد ${unit.unitNumber} حذف شود؟`)) return

    await api(`/units/${unit.id}`, { method: 'DELETE' })
    reload()
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
            واحدها
          </h1>
          <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
            {data ? `${formatNumber(data.meta.total)} واحد ثبت شده` : 'در حال بارگذاری…'}
          </p>
        </div>

        <div className="flex flex-1 flex-wrap items-center justify-end gap-2">
          <SearchInput onSearch={handleSearch} placeholder="جستجوی شماره واحد…" />
          <button
            onClick={() => setCreating(true)}
            className="flex items-center gap-1.5 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white transition-transform hover:scale-[1.03]"
            style={{ backgroundColor: 'var(--color-brand-500)' }}
          >
            <Plus size={16} />
            واحد جدید
          </button>
        </div>
      </header>

      {isLoading && <LoadingState rows={5} />}
      {error && <ErrorState message={error} onRetry={reload} />}

      {data && !isLoading && (
        <Card>
          {data.data.length === 0 ? (
            <EmptyState
              message={search ? 'واحدی با این مشخصات پیدا نشد.' : 'هنوز واحدی ثبت نشده است.'}
              hint={search ? undefined : 'با دکمه‌ی «واحد جدید» اولین واحد را اضافه کنید.'}
            />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[680px] text-right text-[13px]">
                <thead>
                  <tr style={{ color: 'var(--text-tertiary)' }}>
                    <th className="pb-3 font-medium">واحد</th>
                    <th className="pb-3 font-medium">طبقه</th>
                    <th className="pb-3 font-medium">متراژ</th>
                    <th className="pb-3 font-medium">ساکنین</th>
                    <th className="pb-3 font-medium">وضعیت</th>
                    <th className="pb-3 font-medium">بدهی</th>
                    <th className="pb-3 text-left font-medium">عملیات</th>
                  </tr>
                </thead>
                <tbody>
                  {data.data.map((unit, index) => (
                    <motion.tr
                      key={unit.id}
                      initial={{ opacity: 0, y: 6 }}
                      animate={{ opacity: 1, y: 0 }}
                      transition={{ duration: 0.25, delay: Math.min(index * 0.02, 0.3) }}
                      className="border-t"
                      style={{ borderColor: 'var(--border-subtle)' }}
                    >
                      <td className="py-3 font-semibold" style={{ color: 'var(--text-primary)' }}>
                        <span className="flex items-center gap-2">
                          <Building2 size={15} style={{ color: 'var(--color-brand-400)' }} />
                          {unit.unitNumber}
                          {unit.buildingName && (
                            <span className="text-[11px]" style={{ color: 'var(--text-tertiary)' }}>
                              ({unit.buildingName})
                            </span>
                          )}
                        </span>
                      </td>
                      <td className="py-3 tabular-nums" style={{ color: 'var(--text-secondary)' }}>
                        {formatNumber(unit.floor)}
                      </td>
                      <td className="py-3 tabular-nums" style={{ color: 'var(--text-secondary)' }}>
                        {formatNumber(unit.area)}
                      </td>
                      <td className="py-3 tabular-nums" style={{ color: 'var(--text-secondary)' }}>
                        {formatNumber(unit.residentsCount)}
                      </td>
                      <td className="py-3">
                        <span
                          className="rounded-full px-2.5 py-0.5 text-[11px] font-medium"
                          style={{
                            backgroundColor: 'color-mix(in srgb, var(--color-brand-500) 12%, transparent)',
                            color: 'var(--color-brand-600)',
                          }}
                        >
                          {unit.occupancyLabel}
                        </span>
                      </td>
                      <td
                        className="py-3 tabular-nums font-semibold"
                        style={{ color: unit.balance > 0 ? 'var(--color-danger)' : 'var(--state-success)' }}
                      >
                        {formatMoney(unit.balance)}
                      </td>
                      <td className="py-3">
                        <div className="flex items-center justify-end gap-1">
                          <a
                            href={`/units/${unit.id}/statement.pdf`}
                            aria-label={`تسویه‌حساب واحد ${unit.unitNumber}`}
                            title="تسویه‌حساب (PDF)"
                            className="flex h-8 w-8 items-center justify-center rounded-lg transition-colors hover:bg-(--surface-sunken)"
                            style={{ color: 'var(--text-secondary)' }}
                          >
                            <FileText size={15} />
                          </a>
                          <button
                            onClick={() => setEditing(unit)}
                            aria-label={`ویرایش واحد ${unit.unitNumber}`}
                            className="flex h-8 w-8 items-center justify-center rounded-lg transition-colors hover:bg-(--surface-sunken)"
                            style={{ color: 'var(--text-secondary)' }}
                          >
                            <Pencil size={15} />
                          </button>
                          <button
                            onClick={() => handleDelete(unit)}
                            aria-label={`حذف واحد ${unit.unitNumber}`}
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
        title={editing ? `ویرایش واحد ${editing.unitNumber}` : 'واحد جدید'}
        onClose={() => {
          setCreating(false)
          setEditing(null)
        }}
      >
        {data && (
          <UnitForm
            unit={editing}
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
