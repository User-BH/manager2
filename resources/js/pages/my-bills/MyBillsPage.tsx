import { useState } from 'react'
import { motion } from 'framer-motion'
import { Receipt, Wallet, FileDown, CreditCard, ChevronLeft, Loader2 } from 'lucide-react'
import { Card } from '@/components/ui/Card'
import { StatCard } from '@/components/ui/StatCard'
import { Modal } from '@/components/ui/Modal'
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/PageState'
import { useApi } from '@/hooks/useApi'
import { useDocumentTitle } from '@/hooks'
import { api } from '@/lib/api'
import { formatMoney, formatNumber } from '@/lib/format'
import type { BillStatus } from '@/types'

interface MyBill {
  id: number
  unitLabel: string
  periodLabel: string
  ownerAmount: number
  tenantAmount: number
  penaltyAmount: number
  totalAmount: number
  paidAmount: number
  remaining: number
  status: BillStatus
  statusLabel: string
  dueDate: string | null
  pdfUrl: string
  payUrl: string
}

interface BillDetail extends MyBill {
  breakdown: { label: string; amount: number; categoryLabel: string | null }[]
  payments: {
    id: number
    amount: number
    method: string | null
    status: string
    statusLabel: string
    paidAt: string | null
  }[]
}

interface MyBillsResponse {
  data: MyBill[]
  meta: { currentPage: number; lastPage: number; total: number }
  currency: string
  totalDebt: number
}

const STATUS_COLOR: Record<BillStatus, string> = {
  paid: 'var(--state-success)',
  partial: 'var(--color-accent-500)',
  pending: 'var(--state-info)',
  unpaid: 'var(--color-danger)',
}

export function MyBillsPage() {
  const [detail, setDetail] = useState<BillDetail | null>(null)
  const [loadingDetail, setLoadingDetail] = useState(false)

  useDocumentTitle('صورت‌حساب‌های من')

  const { data, error, isLoading, reload } = useApi<MyBillsResponse>('/my-bills')

  async function openDetail(bill: MyBill) {
    setLoadingDetail(true)
    try {
      const { bill: full } = await api<{ bill: BillDetail }>(`/my-bills/${bill.id}`)
      setDetail(full)
    } finally {
      setLoadingDetail(false)
    }
  }

  return (
    <div className="flex flex-col gap-5">
      <header>
        <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
          صورت‌حساب‌های من
        </h1>
        <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
          {data ? `${formatNumber(data.meta.total)} قبض` : 'در حال بارگذاری…'}
        </p>
      </header>

      {isLoading && <LoadingState rows={4} />}
      {error && <ErrorState message={error} onRetry={reload} />}

      {data && !isLoading && (
        <>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <StatCard
              label="بدهی فعلی"
              value={formatMoney(data.totalDebt)}
              unit={data.currency}
              icon={Wallet}
              tone={data.totalDebt > 0 ? 'danger' : 'success'}
            />
            <StatCard
              label="تعداد قبوض"
              value={formatNumber(data.meta.total)}
              icon={Receipt}
              tone="info"
              delay={0.05}
            />
          </div>

          {data.data.length === 0 ? (
            <Card delay={0.1}>
              <EmptyState
                message="هنوز قبضی برای شما صادر نشده است."
                hint="پس از صدور شارژ دوره توسط مدیر، اینجا نمایش داده می‌شود."
              />
            </Card>
          ) : (
            <div className="flex flex-col gap-3">
              {data.data.map((bill, index) => (
                <motion.div
                  key={bill.id}
                  initial={{ opacity: 0, y: 10 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ duration: 0.3, delay: Math.min(index * 0.04, 0.3) }}
                  className="rounded-2xl border p-5"
                  style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
                >
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                      <h2 className="text-[15px] font-bold" style={{ color: 'var(--text-primary)' }}>
                        {bill.periodLabel}
                      </h2>
                      <p className="mt-0.5 text-[11px]" style={{ color: 'var(--text-tertiary)' }}>
                        {bill.unitLabel}
                        {bill.dueDate && ` · سررسید ${bill.dueDate}`}
                      </p>
                    </div>

                    <span
                      className="rounded-full px-2.5 py-0.5 text-[11px] font-medium"
                      style={{
                        backgroundColor: `color-mix(in srgb, ${STATUS_COLOR[bill.status]} 14%, transparent)`,
                        color: STATUS_COLOR[bill.status],
                      }}
                    >
                      {bill.statusLabel}
                    </span>
                  </div>

                  <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <Figure label="مبلغ کل" value={formatMoney(bill.totalAmount)} />
                    <Figure label="پرداخت‌شده" value={formatMoney(bill.paidAmount)} tone="var(--state-success)" />
                    {bill.penaltyAmount > 0 && (
                      <Figure label="جریمه دیرکرد" value={formatMoney(bill.penaltyAmount)} tone="var(--color-danger)" />
                    )}
                    <Figure
                      label="مانده"
                      value={formatMoney(bill.remaining)}
                      tone={bill.remaining > 0 ? 'var(--color-danger)' : 'var(--state-success)'}
                    />
                  </div>

                  <div className="mt-4 flex flex-wrap items-center gap-2 border-t pt-3" style={{ borderColor: 'var(--border-subtle)' }}>
                    <button
                      onClick={() => openDetail(bill)}
                      className="flex items-center gap-1 text-xs font-semibold"
                      style={{ color: 'var(--color-brand-600)' }}
                    >
                      ریز محاسبه و پرداخت‌ها
                      <ChevronLeft size={13} />
                    </button>

                    <span className="flex-1" />

                    <a
                      href={bill.pdfUrl}
                      className="flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium"
                      style={{ borderColor: 'var(--border-default)', color: 'var(--text-secondary)' }}
                    >
                      <FileDown size={13} />
                      فاکتور PDF
                    </a>

                    {bill.remaining > 0 && (
                      <a
                        href={bill.payUrl}
                        className="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-bold text-white"
                        style={{ backgroundColor: 'var(--color-brand-500)' }}
                      >
                        <CreditCard size={13} />
                        پرداخت
                      </a>
                    )}
                  </div>
                </motion.div>
              ))}
            </div>
          )}
        </>
      )}

      <Modal
        open={detail !== null || loadingDetail}
        title={detail ? `ریز صورت‌حساب ${detail.periodLabel}` : 'در حال بارگذاری…'}
        onClose={() => setDetail(null)}
      >
        {loadingDetail && !detail ? (
          <div className="flex justify-center py-10">
            <Loader2 size={22} className="animate-spin" style={{ color: 'var(--color-brand-500)' }} />
          </div>
        ) : detail ? (
          <div className="flex flex-col gap-5">
            <section>
              <h3 className="mb-2 text-[13px] font-bold" style={{ color: 'var(--text-primary)' }}>
                ریز محاسبه
              </h3>
              {detail.breakdown.length === 0 ? (
                <p className="text-xs" style={{ color: 'var(--text-tertiary)' }}>
                  ریز محاسبه‌ای ثبت نشده است.
                </p>
              ) : (
                <ul className="flex flex-col gap-1">
                  {detail.breakdown.map((row, i) => (
                    <li
                      key={i}
                      className="flex items-center justify-between rounded-lg px-3 py-2 text-[13px]"
                      style={{ backgroundColor: 'var(--surface-sunken)' }}
                    >
                      <span style={{ color: 'var(--text-secondary)' }}>
                        {row.label}
                        {row.categoryLabel && (
                          <span className="mr-1.5 text-[10px]" style={{ color: 'var(--text-tertiary)' }}>
                            ({row.categoryLabel})
                          </span>
                        )}
                      </span>
                      <span className="tabular-nums font-semibold" style={{ color: 'var(--text-primary)' }}>
                        {formatMoney(row.amount)}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </section>

            <section>
              <h3 className="mb-2 text-[13px] font-bold" style={{ color: 'var(--text-primary)' }}>
                پرداخت‌ها
              </h3>
              {detail.payments.length === 0 ? (
                <p className="text-xs" style={{ color: 'var(--text-tertiary)' }}>
                  هنوز پرداختی ثبت نشده است.
                </p>
              ) : (
                <ul className="flex flex-col gap-1">
                  {detail.payments.map((payment) => (
                    <li
                      key={payment.id}
                      className="flex items-center justify-between rounded-lg px-3 py-2 text-[13px]"
                      style={{ backgroundColor: 'var(--surface-sunken)' }}
                    >
                      <span style={{ color: 'var(--text-secondary)' }}>
                        {payment.paidAt ?? '—'}
                        {payment.method && ` · ${payment.method}`}
                      </span>
                      <span className="flex items-center gap-2">
                        <span className="text-[10px]" style={{ color: 'var(--text-tertiary)' }}>
                          {payment.statusLabel}
                        </span>
                        <span className="tabular-nums font-semibold" style={{ color: 'var(--state-success)' }}>
                          {formatMoney(payment.amount)}
                        </span>
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </section>
          </div>
        ) : null}
      </Modal>
    </div>
  )
}

function Figure({ label, value, tone }: { label: string; value: string; tone?: string }) {
  return (
    <div>
      <p className="text-[11px]" style={{ color: 'var(--text-tertiary)' }}>
        {label}
      </p>
      <p className="mt-0.5 tabular-nums text-sm font-bold" style={{ color: tone ?? 'var(--text-primary)' }}>
        {value}
      </p>
    </div>
  )
}
