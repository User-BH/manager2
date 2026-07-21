import { useState } from 'react'
import { motion } from 'framer-motion'
import { Check, X, FileText, Clock, Loader2, AlertCircle } from 'lucide-react'
import { Card } from '@/components/ui/Card'
import { StatCard } from '@/components/ui/StatCard'
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/PageState'
import { useApi } from '@/hooks/useApi'
import { useDocumentTitle } from '@/hooks'
import { api, ApiError } from '@/lib/api'
import { confirmAction, promptText, toastSuccess } from '@/lib/alert'
import { formatMoney, formatNumber } from '@/lib/format'

interface PaymentRow {
  id: number
  amount: number
  methodLabel: string | null
  status: string
  statusLabel: string
  unitLabel: string
  payerName: string
  billPeriod: string | null
  description: string | null
  hasReceipt: boolean
  receiptUrl: string | null
  createdAt: string
  paidAt: string | null
}

interface PaymentsResponse {
  currency: string
  pending: PaymentRow[]
  recent: PaymentRow[]
  pendingTotal: number
}

const STATUS_COLOR: Record<string, string> = {
  success: 'var(--state-success)',
  rejected: 'var(--color-danger)',
  pending: 'var(--state-info)',
  failed: 'var(--color-danger)',
}

export function PaymentReviewPage() {
  const [busyId, setBusyId] = useState<number | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)

  useDocumentTitle('بررسی پرداخت‌ها')

  const { data, error, isLoading, reload } = useApi<PaymentsResponse>('/payments')

  async function approve(payment: PaymentRow) {
    const ok = await confirmAction({
      title: `پرداخت ${formatMoney(payment.amount)} تایید شود؟`,
      text: `${payment.unitLabel} — مبلغ به بدهی این واحد اعمال می‌شود.`,
      confirmLabel: 'تایید پرداخت',
    })
    if (!ok) return

    setBusyId(payment.id)
    setActionError(null)
    try {
      await api(`/payments/${payment.id}/approve`, { method: 'POST' })
      toastSuccess('پرداخت تایید شد.')
      reload()
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : 'تایید ناموفق بود.')
    } finally {
      setBusyId(null)
    }
  }

  async function reject(payment: PaymentRow) {
    const note = await promptText({
      title: 'رد کردن رسید',
      text: 'دلیل رد شدن برای ساکن نمایش داده می‌شود.',
      defaultValue: 'رسید نامعتبر',
      placeholder: 'دلیل (اختیاری)',
      confirmLabel: 'رد کن',
    })
    if (note === null) return

    setBusyId(payment.id)
    setActionError(null)
    try {
      await api(`/payments/${payment.id}/reject`, { method: 'POST', body: { note } })
      toastSuccess('رسید رد شد.')
      reload()
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : 'رد کردن ناموفق بود.')
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div className="flex flex-col gap-5">
      <header>
        <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
          بررسی پرداخت‌ها
        </h1>
        <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
          رسیدهای آپلودشده توسط ساکنین را تایید یا رد کنید
        </p>
      </header>

      {actionError && (
        <div
          className="flex items-center gap-2 rounded-xl px-4 py-3 text-sm"
          style={{
            backgroundColor: 'color-mix(in srgb, var(--color-danger) 12%, transparent)',
            color: 'var(--color-danger)',
          }}
        >
          <AlertCircle size={16} />
          {actionError}
        </div>
      )}

      {isLoading && <LoadingState rows={4} />}
      {error && <ErrorState message={error} onRetry={reload} />}

      {data && !isLoading && (
        <>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <StatCard
              label="در انتظار بررسی"
              value={formatNumber(data.pending.length)}
              icon={Clock}
              tone={data.pending.length > 0 ? 'warning' : 'success'}
            />
            <StatCard
              label="مبلغ در انتظار"
              value={formatMoney(data.pendingTotal)}
              unit={data.currency}
              icon={FileText}
              tone="info"
              delay={0.05}
            />
          </div>

          <Card title="در انتظار تایید" delay={0.1}>
            {data.pending.length === 0 ? (
              <EmptyState message="رسید در انتظار بررسی وجود ندارد." />
            ) : (
              <ul className="flex flex-col gap-2">
                {data.pending.map((payment, index) => (
                  <motion.li
                    key={payment.id}
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.25, delay: Math.min(index * 0.04, 0.3) }}
                    className="flex flex-wrap items-center justify-between gap-3 rounded-xl px-4 py-3.5"
                    style={{ backgroundColor: 'var(--surface-sunken)' }}
                  >
                    <div className="min-w-0">
                      <p className="text-[13px] font-semibold" style={{ color: 'var(--text-primary)' }}>
                        {payment.unitLabel} · {payment.payerName}
                      </p>
                      <p className="mt-0.5 text-[11px]" style={{ color: 'var(--text-tertiary)' }}>
                        {payment.createdAt}
                        {payment.billPeriod && ` · قبض ${payment.billPeriod}`}
                        {payment.methodLabel && ` · ${payment.methodLabel}`}
                        {payment.description && ` · ${payment.description}`}
                      </p>
                    </div>

                    <div className="flex items-center gap-2">
                      <span className="tabular-nums text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
                        {formatMoney(payment.amount)}
                      </span>

                      {payment.hasReceipt && payment.receiptUrl && (
                        <a
                          href={payment.receiptUrl}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium"
                          style={{ borderColor: 'var(--border-default)', color: 'var(--text-secondary)' }}
                        >
                          <FileText size={13} />
                          رسید
                        </a>
                      )}

                      <button
                        onClick={() => approve(payment)}
                        disabled={busyId !== null}
                        className="flex items-center gap-1 rounded-lg px-3 py-1.5 text-xs font-bold text-white disabled:opacity-60"
                        style={{ backgroundColor: 'var(--state-success)' }}
                      >
                        {busyId === payment.id ? <Loader2 size={13} className="animate-spin" /> : <Check size={13} />}
                        تایید
                      </button>

                      <button
                        onClick={() => reject(payment)}
                        disabled={busyId !== null}
                        className="flex items-center gap-1 rounded-lg px-3 py-1.5 text-xs font-bold text-white disabled:opacity-60"
                        style={{ backgroundColor: 'var(--color-danger)' }}
                      >
                        <X size={13} />
                        رد
                      </button>
                    </div>
                  </motion.li>
                ))}
              </ul>
            )}
          </Card>

          <Card title="بررسی‌شده‌های اخیر" delay={0.15}>
            {data.recent.length === 0 ? (
              <EmptyState message="سابقه‌ای موجود نیست." />
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[620px] text-right text-[13px]">
                  <thead>
                    <tr style={{ color: 'var(--text-tertiary)' }}>
                      <th className="pb-3 font-medium">واحد</th>
                      <th className="pb-3 font-medium">پرداخت‌کننده</th>
                      <th className="pb-3 font-medium">مبلغ</th>
                      <th className="pb-3 font-medium">تاریخ</th>
                      <th className="pb-3 font-medium">وضعیت</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.recent.map((payment) => (
                      <tr key={payment.id} className="border-t" style={{ borderColor: 'var(--border-subtle)' }}>
                        <td className="py-2.5 font-medium" style={{ color: 'var(--text-primary)' }}>
                          {payment.unitLabel}
                        </td>
                        <td className="py-2.5" style={{ color: 'var(--text-secondary)' }}>
                          {payment.payerName}
                        </td>
                        <td className="py-2.5 tabular-nums" style={{ color: 'var(--text-secondary)' }}>
                          {formatMoney(payment.amount)}
                        </td>
                        <td className="py-2.5 tabular-nums" style={{ color: 'var(--text-tertiary)' }}>
                          {payment.paidAt ?? payment.createdAt}
                        </td>
                        <td className="py-2.5">
                          <span
                            className="rounded-full px-2.5 py-0.5 text-[11px] font-medium"
                            style={{
                              backgroundColor: `color-mix(in srgb, ${STATUS_COLOR[payment.status] ?? 'var(--text-tertiary)'} 14%, transparent)`,
                              color: STATUS_COLOR[payment.status] ?? 'var(--text-tertiary)',
                            }}
                          >
                            {payment.statusLabel}
                          </span>
                        </td>
                      </tr>
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
