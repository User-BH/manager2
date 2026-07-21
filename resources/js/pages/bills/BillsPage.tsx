import { useState } from 'react'
import { motion } from 'framer-motion'
import { Receipt, Loader2, Sparkles, FileSpreadsheet } from 'lucide-react'
import { Card } from '@/components/ui/Card'
import { StatCard } from '@/components/ui/StatCard'
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/PageState'
import { useApi } from '@/hooks/useApi'
import { useDocumentTitle } from '@/hooks'
import { api } from '@/lib/api'
import { alertError, confirmAction, toastSuccess } from '@/lib/alert'
import { formatMoney, formatNumber } from '@/lib/format'
import type { BillStatus } from '@/types'

interface Bill {
  id: number
  unitLabel: string
  ownerAmount: number
  tenantAmount: number
  penaltyAmount: number
  totalAmount: number
  paidAmount: number
  status: BillStatus
  statusLabel: string
  dueDate: string | null
}

interface BillsResponse {
  period: string
  periodLabel: string
  periods: { value: string; label: string }[]
  currency: string
  total: number
  collected: number
  data: Bill[]
}

const STATUS_COLOR: Record<BillStatus, string> = {
  paid: 'var(--state-success)',
  partial: 'var(--color-accent-500)',
  pending: 'var(--state-info)',
  unpaid: 'var(--color-danger)',
}

export function BillsPage() {
  const [period, setPeriod] = useState<string>('')
  const [generating, setGenerating] = useState(false)

  useDocumentTitle('قبوض و شارژ')

  const query = period ? `/bills?period=${encodeURIComponent(period)}` : '/bills'
  const { data, error, isLoading, reload } = useApi<BillsResponse>(query)

  async function handleGenerate() {
    if (!data) return

    const ok = await confirmAction({
      title: `قبوض دوره‌ی ${data.periodLabel} صادر شود؟`,
      text: 'قبض‌های موجودِ همین دوره با مبالغ تازه به‌روزرسانی می‌شوند.',
      confirmLabel: 'صدور کن',
    })
    if (!ok) return

    setGenerating(true)
    try {
      await api('/bills/generate', { method: 'POST', body: { period: data.period } })
      toastSuccess(`قبوض ${data.periodLabel} صادر شد.`)
      reload()
    } catch (error) {
      alertError(error, 'صدور قبوض ممکن نشد.')
    } finally {
      setGenerating(false)
    }
  }

  return (
    <div className="flex flex-col gap-5">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
            قبوض و شارژ
          </h1>
          <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
            {data ? data.periodLabel : 'در حال بارگذاری…'}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {data && (
            <select
              value={data.period}
              onChange={(e) => setPeriod(e.target.value)}
              className="rounded-xl border px-3 py-2.5 text-[13px] outline-none"
              style={{
                backgroundColor: 'var(--surface-sunken)',
                borderColor: 'var(--border-subtle)',
                color: 'var(--text-primary)',
              }}
            >
              {data.periods.map((p) => (
                <option key={p.value} value={p.value}>
                  {p.label}
                </option>
              ))}
            </select>
          )}

          {data && data.data.length > 0 && (
            <a
              href={`/bills/export.xlsx?period=${encodeURIComponent(data.period)}`}
              className="flex items-center gap-1.5 rounded-xl border px-4 py-2.5 text-[13px] font-semibold"
              style={{ borderColor: 'var(--border-default)', color: 'var(--text-secondary)' }}
            >
              <FileSpreadsheet size={15} />
              خروجی Excel
            </a>
          )}

          <button
            onClick={handleGenerate}
            disabled={generating || !data}
            className="flex items-center gap-1.5 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white transition-transform hover:scale-[1.03] disabled:opacity-60"
            style={{ backgroundColor: 'var(--color-brand-500)' }}
          >
            {generating ? <Loader2 size={16} className="animate-spin" /> : <Sparkles size={16} />}
            صدور قبوض دوره
          </button>
        </div>
      </header>

      {isLoading && <LoadingState rows={5} />}
      {error && <ErrorState message={error} onRetry={reload} />}

      {data && !isLoading && (
        <>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <StatCard label="تعداد قبوض" value={formatNumber(data.data.length)} icon={Receipt} tone="info" />
            <StatCard label="مبلغ کل صادرشده" value={formatMoney(data.total)} unit={data.currency} icon={Receipt} tone="warning" delay={0.05} />
            <StatCard label="وصول‌شده" value={formatMoney(data.collected)} unit={data.currency} icon={Receipt} tone="success" delay={0.1} />
          </div>

          <Card delay={0.15}>
            {data.data.length === 0 ? (
              <EmptyState
                message="برای این دوره قبضی صادر نشده است."
                hint="روی «صدور قبوض دوره» بزنید."
              />
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[760px] text-right text-[13px]">
                  <thead>
                    <tr style={{ color: 'var(--text-tertiary)' }}>
                      <th className="pb-3 font-medium">واحد</th>
                      <th className="pb-3 font-medium">مالکانه</th>
                      <th className="pb-3 font-medium">مستاجرانه</th>
                      <th className="pb-3 font-medium">جریمه</th>
                      <th className="pb-3 font-medium">کل</th>
                      <th className="pb-3 font-medium">پرداخت‌شده</th>
                      <th className="pb-3 font-medium">سررسید</th>
                      <th className="pb-3 font-medium">وضعیت</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.data.map((bill, index) => (
                      <motion.tr
                        key={bill.id}
                        initial={{ opacity: 0, y: 6 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.25, delay: Math.min(index * 0.02, 0.3) }}
                        className="border-t"
                        style={{ borderColor: 'var(--border-subtle)' }}
                      >
                        <td className="py-3 font-semibold" style={{ color: 'var(--text-primary)' }}>
                          {bill.unitLabel}
                        </td>
                        <td className="py-3 tabular-nums" style={{ color: 'var(--text-secondary)' }}>
                          {formatMoney(bill.ownerAmount)}
                        </td>
                        <td className="py-3 tabular-nums" style={{ color: 'var(--text-secondary)' }}>
                          {formatMoney(bill.tenantAmount)}
                        </td>
                        <td className="py-3 tabular-nums" style={{ color: 'var(--color-danger)' }}>
                          {bill.penaltyAmount > 0 ? formatMoney(bill.penaltyAmount) : '—'}
                        </td>
                        <td className="py-3 tabular-nums font-semibold" style={{ color: 'var(--text-primary)' }}>
                          {formatMoney(bill.totalAmount)}
                        </td>
                        <td className="py-3 tabular-nums" style={{ color: 'var(--state-success)' }}>
                          {formatMoney(bill.paidAmount)}
                        </td>
                        <td className="py-3 tabular-nums" style={{ color: 'var(--text-tertiary)' }}>
                          {bill.dueDate ?? '—'}
                        </td>
                        <td className="py-3">
                          <span
                            className="rounded-full px-2.5 py-0.5 text-[11px] font-medium"
                            style={{
                              backgroundColor: `color-mix(in srgb, ${STATUS_COLOR[bill.status]} 14%, transparent)`,
                              color: STATUS_COLOR[bill.status],
                            }}
                          >
                            {bill.statusLabel}
                          </span>
                        </td>
                      </motion.tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>
        </>
      )}
    </div>
  )
}
