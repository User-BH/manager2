import { motion } from 'framer-motion'
import { BadgeCheck, Crown, FileText, Hourglass } from 'lucide-react'
import { Card } from '@/shared/ui/Card'
import { StatCard } from '@/shared/ui/StatCard'
import { EmptyState, ErrorState } from '@/shared/ui/PageState'
import { TableSkeleton } from '@/shared/ui/Skeleton'
import { useQuery } from '@tanstack/react-query'
import { queryKeys } from '@/shared/lib/queryKeys'
import { useDocumentTitle } from '@/shared/hooks'
import { api } from '@/shared/lib/api'
import { formatMoney, formatNumber } from '@/shared/lib/format'
import { alertError, promptText, toastSuccess } from '@/shared/lib/alert'

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

/** خریدِ مجتمعِ تازه که به آدم نیاز دارد (۱۲/۲) */
interface CheckoutAttention {
  id: string
  status: 'paid' | 'pending'
  statusLabel: string
  buyerName: string | null
  buyerPhone: string | null
  complexName: string
  planName: string
  amountLabel: string
  trackingCode: string | null
  reason: string | null
  createdAt: string
}

interface Response {
  pending: SubscriptionRequest[]
  recent: SubscriptionRequest[]
  pendingTotal: number
  activeCount: number
  checkouts?: CheckoutAttention[]
}

const STATUS_COLOR: Record<string, string> = {
  active: 'var(--state-success)',
  pending: 'var(--state-info)',
  failed: 'var(--color-danger)',
  canceled: 'var(--text-tertiary)',
}

/**
 * اشتراک‌های مجتمع‌ها — فقط دیدن، فقط ادمینِ کل (مرحلهٔ ۱۲ · فاز ۲).
 *
 * خرید و تمدید فقط آنلاین‌اند و فقط تأییدِ مستقلِ درگاه در سرور فعالشان می‌کند؛ این صفحه هیچ دکمه‌ی «تأیید» یا
 * «رد» ندارد. ردیفِ در انتظار یعنی «در انتظارِ نتیجه‌ی درگاه» و بازبینیِ خودکار با پرسیدن از درگاه تکلیفش را روشن
 * می‌کند. رسیدهای پیش از ۱۲/۲ برای سابقه دیده می‌شوند.
 */
export function SubscriptionsPage() {
  useDocumentTitle('اشتراک‌ها')

  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.system.subscriptions(),
    queryFn: ({ signal }) => api<Response>('/system/subscriptions', { signal }),
  })

  /*
   * ثبتِ بازپرداختِ «پرداخت شد ولی مجتمع ساخته نشد» — پس از برگرداندنِ پول در بانک (۱۲/۲). فقط ثبت است؛ هیچ چیزی
   * فعال نمی‌شود. سرور تأییدِ تازه‌ی رمز می‌خواهد.
   */
  async function recordRefund(checkout: CheckoutAttention) {
    const reference = await promptText({
      title: `بازپرداختِ ${checkout.amountLabel} تومان به ${checkout.buyerName ?? 'خریدار'}`,
      text: 'پس از برگرداندنِ پول در بانک، مرجعِ بازپرداخت را وارد کنید. این کار فقط ثبت است و برگشت‌پذیر نیست.',
      defaultValue: '',
      confirmLabel: 'ثبتِ بازپرداخت',
    })
    if (!reference) return

    try {
      await api(`/system/checkouts/${checkout.id}/refund`, { method: 'POST', body: { reference } })
      toastSuccess('بازپرداخت ثبت شد.')
      void refetch()
    } catch (err) {
      alertError(err, 'ثبتِ بازپرداخت ممکن نشد.')
    }
  }

  return (
    <div className="flex flex-col gap-5">
      <header>
        <h1
          className="flex items-center gap-2 text-xl font-extrabold"
          style={{ color: 'var(--text-primary)' }}
        >
          <Crown size={19} style={{ color: 'var(--color-brand-500)' }} />
          اشتراک‌ها
        </h1>
        <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
          پایشِ اشتراکِ مجتمع‌ها — فعال‌سازی فقط با تأییدِ درگاهِ آنلاین
        </p>
      </header>

      {isLoading && <TableSkeleton rows={5} columns={5} />}
      {error && <ErrorState error={error} onRetry={() => void refetch()} />}

      {data && !isLoading && (
        <>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <StatCard
              label="در انتظارِ درگاه"
              value={formatNumber(data.pending.length)}
              icon={Hourglass}
              tone="info"
            />
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

          <Card title="در انتظارِ نتیجه‌ی درگاه" delay={0.15}>
            {data.pending.length === 0 ? (
              <EmptyState
                message="پرداختِ در انتظاری نیست."
                hint="پرداخت‌های آنلاینی که نتیجه‌شان هنوز از درگاه نیامده این‌جا دیده می‌شوند؛ بازبینیِ خودکار تکلیفشان را روشن می‌کند."
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
                    style={{
                      borderColor: 'var(--border-subtle)',
                      backgroundColor: 'var(--surface-sunken)',
                    }}
                  >
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div className="min-w-0">
                        <h3
                          className="text-[14px] font-bold"
                          style={{ color: 'var(--text-primary)' }}
                        >
                          {request.complexName}
                        </h3>
                        <p className="mt-1 text-[11.5px]" style={{ color: 'var(--text-tertiary)' }}>
                          {request.planLabel} · {request.methodLabel} · خریدار: {request.buyerName}
                          {request.buyerPhone && ` (${request.buyerPhone})`}
                        </p>
                        <p
                          className="mt-0.5 text-[11.5px]"
                          style={{ color: 'var(--text-tertiary)' }}
                        >
                          ثبت: {request.createdAt}
                          {request.paidOn && ` · تاریخ واریز: ${request.paidOn}`}
                        </p>
                        {request.note && (
                          <p
                            className="mt-1.5 text-[11.5px]"
                            style={{ color: 'var(--text-secondary)' }}
                          >
                            توضیح: {request.note}
                          </p>
                        )}
                      </div>

                      <span
                        className="text-[15px] font-extrabold tabular-nums"
                        style={{ color: 'var(--color-brand-600)' }}
                      >
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
                          style={{
                            borderColor: 'var(--border-default)',
                            color: 'var(--text-secondary)',
                          }}
                        >
                          <FileText size={13} />
                          مشاهده رسید
                        </a>
                      )}
                    </div>
                  </motion.article>
                ))}
              </div>
            )}
          </Card>

          <Card title="خریدهای نیازمندِ رسیدگی" delay={0.18}>
            {(data.checkouts ?? []).length === 0 ? (
              <EmptyState
                message="خریدی منتظرِ رسیدگی نیست."
                hint="پرداختی که تأیید شد ولی مجتمعش ساخته نشد، یا پرداختی که بازبینیِ خودکار هنوز تکلیفش را نمی‌داند، این‌جا می‌آید."
              />
            ) : (
              <ul className="flex flex-col gap-2">
                {(data.checkouts ?? []).map((checkout) => (
                  <li
                    key={checkout.id}
                    className="flex flex-wrap items-center gap-3 rounded-2xl border p-3"
                    style={{ borderColor: 'var(--border-subtle)' }}
                  >
                    <div className="min-w-0 flex-1">
                      <p
                        className="text-[13.5px] font-bold"
                        style={{ color: 'var(--text-primary)' }}
                      >
                        {checkout.complexName} · {checkout.planName} · {checkout.amountLabel} تومان
                      </p>
                      <p className="mt-0.5 text-[11.5px]" style={{ color: 'var(--text-tertiary)' }}>
                        {checkout.statusLabel} · {checkout.buyerName ?? '—'}
                        {checkout.buyerPhone && ` (${checkout.buyerPhone})`} · {checkout.createdAt}
                        {checkout.trackingCode && ` · رهگیری ${checkout.trackingCode}`}
                      </p>
                      {checkout.reason && (
                        <p
                          className="mt-0.5 text-[11.5px]"
                          style={{ color: 'var(--text-secondary)' }}
                        >
                          {checkout.reason}
                        </p>
                      )}
                    </div>
                    {checkout.status === 'paid' && (
                      <button
                        type="button"
                        onClick={() => void recordRefund(checkout)}
                        className="rounded-lg border px-3 py-1.5 text-xs font-semibold"
                        style={{
                          borderColor: 'var(--border-default)',
                          color: 'var(--text-secondary)',
                        }}
                      >
                        ثبتِ بازپرداخت
                      </button>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <Card title="اخیر" delay={0.2}>
            {data.recent.length === 0 ? (
              <EmptyState message="هنوز اشتراکی ثبت نشده است." />
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
                      <tr
                        key={row.id}
                        className="border-t"
                        style={{ borderColor: 'var(--border-subtle)' }}
                      >
                        <td className="py-3 font-semibold" style={{ color: 'var(--text-primary)' }}>
                          {row.complexName}
                        </td>
                        <td className="py-3" style={{ color: 'var(--text-secondary)' }}>
                          {row.planLabel}
                        </td>
                        <td
                          className="py-3 tabular-nums"
                          style={{ color: 'var(--text-secondary)' }}
                        >
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
