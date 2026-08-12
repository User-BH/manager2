import { useState } from 'react'
import { motion } from 'framer-motion'
import { Receipt, Loader2, Sparkles, FileSpreadsheet, FileText } from 'lucide-react'
import { Card } from '@/shared/ui/Card'
import { StatCard } from '@/shared/ui/StatCard'
import { EmptyState, ErrorState } from '@/shared/ui/PageState'
import { TableSkeleton } from '@/shared/ui/Skeleton'
import { useQuery, useMutation } from '@tanstack/react-query'
import { errorMessage } from '@/shared/lib/queryClient'
import { queryKeys } from '@/shared/lib/queryKeys'
import { cn } from '@/shared/lib/cn'
import { useDocumentTitle, useVirtualRows } from '@/shared/hooks'
import { api } from '@/shared/lib/api'
import { alertError, confirmAction, toastSuccess } from '@/shared/lib/alert'
import { formatMoney, formatNumber } from '@/shared/lib/format'
import type { BillStatus } from '@/shared/types'

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

/**
 * ارتفاعِ ثابتِ هر ردیف (پیکسل) — باید با CSSِ ردیف بخواند.
 *
 * ⚠️ اگر padding یا اندازه‌ی قلمِ ردیف عوض شد، این عدد هم باید عوض شود؛
 * وگرنه پنجره‌ی مجازی می‌لغزد و ردیف‌ها روی هم می‌افتند. خطا نمی‌دهد، فقط
 * بد دیده می‌شود.
 */
const ROW_HEIGHT = 45

/** زیرِ این تعداد، مجازی‌سازی سود ندارد و فقط انیمیشن را خراب می‌کند. */
const VIRTUAL_THRESHOLD = 150

export function BillsPage() {
  const [period, setPeriod] = useState<string>('')
  const [generating, setGenerating] = useState(false)

  useDocumentTitle('قبوض و شارژ')

  /*
   * ساختِ دسته‌ی PDF در صف (R28).
   *
   * پاسخ ۲۰۲ است و نه فایل: کاربر پیام می‌گیرد که کار شروع شده و سند در
   * فهرستِ اسناد ظاهر می‌شود. اگر همین‌جا منتظرِ فایل می‌ماندیم، مجتمعِ
   * بزرگ به تایم‌اوت می‌خورد.
   */
  const bundle = useMutation({
    mutationFn: (period: string) =>
      api<{ document: { title: string } }>('/documents/bills-bundle', {
        method: 'POST',
        body: { period },
      }),
    onSuccess: ({ document }) =>
      toastSuccess(`«${document.title}» در حال ساخت است؛ پس از آماده‌شدن قابل دانلود می‌شود.`),
    onError: (err) => alertError(err, 'درخواست ساخت دسته‌ی PDF ممکن نشد.'),
  })

  const query = period ? `/bills?period=${encodeURIComponent(period)}` : '/bills'
  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.bills.list({ period }),
    queryFn: ({ signal }) => api<BillsResponse>(query, { signal }),
  })
  /*
   * پنجره‌ی مجازی (R30).
   *
   * قلاب همیشه صدا زده می‌شود (قاعده‌ی قلاب‌ها)، ولی نتیجه‌اش فقط وقتی
   * استفاده می‌شود که فهرست واقعاً بلند باشد.
   */
  const total = data?.data.length ?? 0
  const isVirtual = total > VIRTUAL_THRESHOLD
  /*
   * خروجیِ قلاب **باز** می‌شود و به‌صورت شیء نگه داشته نمی‌شود.
   *
   * قاعده‌ی `react-hooks/refs` هر دسترسی به عضوِ شیئی که ref دارد را
   * «خواندنِ ref هنگام رندر» می‌شمارد — حتی اگر آن عضو یک عددِ ساده باشد.
   * با باز کردن، هم اخطار می‌رود و هم خواناتر است.
   */
  const { containerRef, start, end, totalHeight, offsetTop, isMeasured } = useVirtualRows({
    count: total,
    rowHeight: ROW_HEIGHT,
  })

  const rows = isVirtual && isMeasured ? (data?.data ?? []).slice(start, end) : (data?.data ?? [])

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
      void refetch()
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

          {/*
            دسته‌ی PDFِ همه‌ی قبض‌های دوره (R28). چون برای مجتمعِ بزرگ
            ده‌ها ثانیه طول می‌کشد، در صف ساخته می‌شود و کاربر بلافاصله
            ردیفش را با وضعیتِ «در حال ساخت» می‌بیند.
          */}
          {data && data.data.length > 0 && (
            <button
              type="button"
              onClick={() => bundle.mutate(data.period)}
              disabled={bundle.isPending}
              className="flex items-center gap-1.5 rounded-xl border px-4 py-2.5 text-[13px] font-semibold disabled:opacity-60"
              style={{ borderColor: 'var(--border-default)', color: 'var(--text-secondary)' }}
            >
              {bundle.isPending ? (
                <Loader2 size={15} className="animate-spin" />
              ) : (
                <FileText size={15} />
              )}
              دسته‌ی PDF قبض‌ها
            </button>
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

      {isLoading && <TableSkeleton rows={6} columns={5} />}
      {error && <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />}

      {data && !isLoading && (
        <>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <StatCard
              label="تعداد قبوض"
              value={formatNumber(data.data.length)}
              icon={Receipt}
              tone="info"
            />
            <StatCard
              label="مبلغ کل صادرشده"
              value={formatMoney(data.total)}
              unit={data.currency}
              icon={Receipt}
              tone="warning"
              delay={0.05}
            />
            <StatCard
              label="وصول‌شده"
              value={formatMoney(data.collected)}
              unit={data.currency}
              icon={Receipt}
              tone="success"
              delay={0.1}
            />
          </div>

          <Card delay={0.15}>
            {data.data.length === 0 ? (
              <EmptyState
                message="برای این دوره قبضی صادر نشده است."
                hint="روی «صدور قبوض دوره» بزنید."
              />
            ) : (
              /*
                فهرستِ بلند **مجازی** رندر می‌شود (R30).

                زیرِ آستانه هیچ چیزی عوض نمی‌شود: جدول عادی با همان
                انیمیشنِ ورودِ ردیف‌ها. بالای آستانه ظرف ارتفاعِ ثابت
                می‌گیرد و فقط ردیف‌های داخلِ قاب رندر می‌شوند، پس مجتمعِ
                ۵۰۰ واحدی هم ~۲۰ ردیفِ DOM دارد.

                ارتفاعِ ردیف باید با CSS بخواند (`py-3` + متن ≈ ۴۵px)؛
                اگر روزی ردیف بلندتر شد، `ROW_HEIGHT` هم باید عوض شود
                وگرنه محاسبه می‌لغزد.
              */
              <div
                ref={containerRef}
                className={cn('overflow-x-auto', isVirtual && 'scrollbar-thin overflow-y-auto')}
                style={isVirtual ? { maxHeight: '70vh' } : undefined}
              >
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
                    {/* فاصله‌گذارِ بالا: جای ردیف‌های رندرنشده را نگه می‌دارد */}
                    {isVirtual && offsetTop > 0 && <tr style={{ height: offsetTop }} aria-hidden />}

                    {rows.map((bill, index) => (
                      <motion.tr
                        key={bill.id}
                        initial={{ opacity: 0, y: 6 }}
                        animate={{ opacity: 1, y: 0 }}
                        /*
                          در حالتِ مجازی انیمیشن خاموش است: ردیف‌ها با
                          اسکرول مدام mount/unmount می‌شوند و انیمیشنِ
                          ورود، فهرست را در حالِ اسکرول چشمک‌زن می‌کرد.
                        */
                        transition={
                          isVirtual
                            ? { duration: 0 }
                            : { duration: 0.25, delay: Math.min(index * 0.02, 0.3) }
                        }
                        style={{ borderColor: 'var(--border-subtle)', height: ROW_HEIGHT }}
                        className="border-t"
                      >
                        <td className="py-3 font-semibold" style={{ color: 'var(--text-primary)' }}>
                          {bill.unitLabel}
                        </td>
                        <td
                          className="py-3 tabular-nums"
                          style={{ color: 'var(--text-secondary)' }}
                        >
                          {formatMoney(bill.ownerAmount)}
                        </td>
                        <td
                          className="py-3 tabular-nums"
                          style={{ color: 'var(--text-secondary)' }}
                        >
                          {formatMoney(bill.tenantAmount)}
                        </td>
                        <td className="py-3 tabular-nums" style={{ color: 'var(--color-danger)' }}>
                          {bill.penaltyAmount > 0 ? formatMoney(bill.penaltyAmount) : '—'}
                        </td>
                        <td
                          className="py-3 tabular-nums font-semibold"
                          style={{ color: 'var(--text-primary)' }}
                        >
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
                    {/* فاصله‌گذارِ پایین، تا نوارِ اسکرول طولِ واقعی را نشان بدهد */}
                    {isVirtual && (
                      <tr
                        style={{ height: totalHeight - offsetTop - rows.length * ROW_HEIGHT }}
                        aria-hidden
                      />
                    )}
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
