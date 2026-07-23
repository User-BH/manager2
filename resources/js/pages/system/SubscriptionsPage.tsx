import { useState } from 'react'
import { motion } from 'framer-motion'
import { BadgeCheck, Check, Crown, FileText, Hourglass, Loader2, X } from 'lucide-react'
import { Card } from '@/components/ui/Card'
import { StatCard } from '@/components/ui/StatCard'
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/PageState'
import { useApi } from '@/hooks/useApi'
import { useDocumentTitle } from '@/hooks'
import { api } from '@/lib/api'
import { alertError, confirmAction, promptText, toastSuccess } from '@/lib/alert'
import { formatMoney, formatNumber } from '@/lib/format'

interface SubscriptionRequest {
  id: number
  complexName: string
  buyerName: string
  buyerPhone: string | null
  planLabel: string
  months: number
  amount: number
  amountLabel: string
  status: string
  statusLabel: string
  methodLabel: string
  paidOn: string | null
  note: string | null
  hasReceipt: boolean
  receiptUrl: string | null
  reviewedBy: string | null
  reviewedAt: string | null
  endsAt: string | null
  createdAt: string
}

interface Response {
  pending: SubscriptionRequest[]
  recent: SubscriptionRequest[]
  pendingTotal: number
  activeCount: number
}

const STATUS_COLOR: Record<string, string> = {
  active: 'var(--state-success)',
  pending: 'var(--state-info)',
  failed: 'var(--color-danger)',
  canceled: 'var(--text-tertiary)',
}

/**
 * بررسی رسیدهای اشتراک — فقط ادمین کل.
 *
 * پول اشتراک به حساب سرویس‌دهنده می‌رود و پرداخت‌کننده خودِ مدیر مجتمع
 * است؛ پس تاییدش نمی‌تواند کار همان مدیر باشد. (این با «بررسی پرداخت‌ها»ی
 * شارژ فرق دارد که آنجا ساکن می‌پردازد و مدیر تایید می‌کند.)
 */
export function SubscriptionsPage() {
  const [busyId, setBusyId] = useState<number | null>(null)

  useDocumentTitle('اشتراک‌ها')

  const { data, error, isLoading, reload } = useApi<Response>('/system/subscriptions')

  async function approve(request: SubscriptionRequest) {
    const ok = await confirmAction({
      title: `اشتراک ${request.complexName} تایید شود؟`,
      text: `${request.planLabel} — ${formatMoney(request.amount)} تومان. اشتراک از همین لحظه به مدت ${formatNumber(request.months)} ماه فعال می‌شود.`,
      confirmLabel: 'تایید و فعال‌سازی',
    })
    if (!ok) return

    setBusyId(request.id)
    try {
      await api(`/system/subscriptions/${request.id}/approve`, { method: 'POST' })
      toastSuccess('اشتراک فعال شد.')
      reload()
    } catch (err) {
      alertError(err, 'تایید اشتراک ممکن نشد.')
    } finally {
      setBusyId(null)
    }
  }

  async function reject(request: SubscriptionRequest) {
    const note = await promptText({
      title: 'رد درخواست اشتراک',
      text: 'دلیل رد برای مدیر مجتمع نمایش داده می‌شود.',
      defaultValue: 'رسید تایید نشد',
      confirmLabel: 'رد کن',
    })
    if (note === null) return

    setBusyId(request.id)
    try {
      await api(`/system/subscriptions/${request.id}/reject`, { method: 'POST', body: { note } })
      toastSuccess('درخواست رد شد.')
      reload()
    } catch (err) {
      alertError(err, 'رد درخواست ممکن نشد.')
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div className="flex flex-col gap-5">
      <header>
        <h1 className="flex items-center gap-2 text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
          <Crown size={19} style={{ color: 'var(--color-brand-500)' }} />
          اشتراک‌ها
        </h1>
        <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
          بررسی رسیدهای واریز و فعال‌سازی اشتراک مجتمع‌ها
        </p>
      </header>

      {isLoading && <LoadingState rows={4} />}
      {error && <ErrorState message={error} onRetry={reload} />}

      {data && !isLoading && (
        <>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <StatCard label="در انتظار بررسی" value={formatNumber(data.pending.length)} icon={Hourglass} tone="info" />
            <StatCard
              label="مبلغ در انتظار"
              value={formatMoney(data.pendingTotal)}
              unit="تومان"
              icon={FileText}
              tone="warning"
              delay={0.05}
            />
            <StatCard
              label="اشتراک فعال"
              value={formatNumber(data.activeCount)}
              icon={BadgeCheck}
              tone="success"
              delay={0.1}
            />
          </div>

          <Card title="در انتظار بررسی" delay={0.15}>
            {data.pending.length === 0 ? (
              <EmptyState
                message="درخواست بررسی‌نشده‌ای نیست."
                hint="رسیدهای تازه‌ی مدیران مجتمع اینجا نمایش داده می‌شوند."
              />
            ) : (
              <div className="flex flex-col gap-3">
                {data.pending.map((request, index) => (
                  <motion.article
                    key={request.id}
                    initial={{ opacity: 0, y: 10 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.3, delay: Math.min(index * 0.05, 0.3) }}
                    className="rounded-2xl border p-4"
                    style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-sunken)' }}
                  >
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div className="min-w-0">
                        <h3 className="text-[14px] font-bold" style={{ color: 'var(--text-primary)' }}>
                          {request.complexName}
                        </h3>
                        <p className="mt-1 text-[11.5px]" style={{ color: 'var(--text-tertiary)' }}>
                          {request.planLabel} · {request.methodLabel} · خریدار: {request.buyerName}
                          {request.buyerPhone && ` (${request.buyerPhone})`}
                        </p>
                        <p className="mt-0.5 text-[11.5px]" style={{ color: 'var(--text-tertiary)' }}>
                          ثبت: {request.createdAt}
                          {request.paidOn && ` · تاریخ واریز: ${request.paidOn}`}
                        </p>
                        {request.note && (
                          <p className="mt-1.5 text-[11.5px]" style={{ color: 'var(--text-secondary)' }}>
                            توضیح: {request.note}
                          </p>
                        )}
                      </div>

                      <span className="text-[15px] font-extrabold tabular-nums" style={{ color: 'var(--color-brand-600)' }}>
                        {request.amountLabel}
                      </span>
                    </div>

                    <div
                      className="mt-3 flex flex-wrap items-center gap-2 border-t pt-3"
                      style={{ borderColor: 'var(--border-subtle)' }}
                    >
                      {request.receiptUrl && (
                        <a
                          href={request.receiptUrl}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold"
                          style={{ borderColor: 'var(--border-default)', color: 'var(--text-secondary)' }}
                        >
                          <FileText size={13} />
                          مشاهده رسید
                        </a>
                      )}

                      <span className="flex-1" />

                      <button
                        onClick={() => void reject(request)}
                        disabled={busyId === request.id}
                        className="flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold disabled:opacity-60"
                        style={{ borderColor: 'var(--border-default)', color: 'var(--color-danger)' }}
                      >
                        <X size={13} />
                        رد
                      </button>

                      <button
                        onClick={() => void approve(request)}
                        disabled={busyId === request.id}
                        className="flex items-center gap-1.5 rounded-lg px-4 py-1.5 text-xs font-bold text-white disabled:opacity-60"
                        style={{ backgroundColor: 'var(--color-brand-500)' }}
                      >
                        {busyId === request.id ? <Loader2 size={13} className="animate-spin" /> : <Check size={13} />}
                        تایید و فعال‌سازی
                      </button>
                    </div>
                  </motion.article>
                ))}
              </div>
            )}
          </Card>

          <Card title="بررسی‌شده‌های اخیر" delay={0.2}>
            {data.recent.length === 0 ? (
              <EmptyState message="هنوز درخواستی بررسی نشده است." />
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[760px] text-right text-[13px]">
                  <thead>
                    <tr style={{ color: 'var(--text-tertiary)' }}>
                      <th className="pb-3 font-medium">مجتمع</th>
                      <th className="pb-3 font-medium">پلن</th>
                      <th className="pb-3 font-medium">مبلغ</th>
                      <th className="pb-3 font-medium">اعتبار تا</th>
                      <th className="pb-3 font-medium">بررسی‌کننده</th>
                      <th className="pb-3 font-medium">وضعیت</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.recent.map((row) => (
                      <tr key={row.id} className="border-t" style={{ borderColor: 'var(--border-subtle)' }}>
                        <td className="py-3 font-semibold" style={{ color: 'var(--text-primary)' }}>
                          {row.complexName}
                        </td>
                        <td className="py-3" style={{ color: 'var(--text-secondary)' }}>
                          {row.planLabel}
                        </td>
                        <td className="py-3 tabular-nums" style={{ color: 'var(--text-secondary)' }}>
                          {row.amountLabel}
                        </td>
                        <td className="py-3 tabular-nums" style={{ color: 'var(--text-tertiary)' }}>
                          {row.endsAt ?? '—'}
                        </td>
                        <td className="py-3" style={{ color: 'var(--text-tertiary)' }}>
                          {row.reviewedBy ?? '—'}
                          {row.reviewedAt && (
                            <span className="block text-[10.5px]">{row.reviewedAt}</span>
                          )}
                        </td>
                        <td className="py-3">
                          <span
                            className="rounded-full px-2.5 py-0.5 text-[11px] font-medium"
                            style={{
                              backgroundColor: `color-mix(in srgb, ${STATUS_COLOR[row.status] ?? 'var(--text-tertiary)'} 14%, transparent)`,
                              color: STATUS_COLOR[row.status] ?? 'var(--text-tertiary)',
                            }}
                          >
                            {row.statusLabel}
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
