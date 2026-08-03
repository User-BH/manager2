import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, Info, Loader2, Send, TriangleAlert } from 'lucide-react'
import { api } from '@/shared/lib/api'
import { alertError, toastSuccess } from '@/shared/lib/alert'
import { errorMessage } from '@/shared/lib/queryClient'
import { queryKeys } from '@/shared/lib/queryKeys'
import { useDocumentTitle } from '@/shared/hooks'
import { Card } from '@/shared/ui/Card'
import { ErrorState, InlineSpinner } from '@/shared/ui/PageState'
import { formatMoney, formatNumber } from '@/shared/lib/format'

interface CampaignHistoryRow {
  id: number
  periodLabel: string
  recipients: number
  delivered: number
  failed: number
  sentBy: string | null
  sentAt: string
  template: string
}

interface CampaignStatus {
  periodLabel: string
  quotaUsed: boolean
  usedAt: string | null
  usedBy: string | null
  blockReason: string | null
  canSend: boolean
  recipientCount: number
  totalDebt: number
  preview: string | null
  history: CampaignHistoryRow[]
}

/**
 * یادآوریِ پیامکیِ شارژ — سهمیه‌ی ماهانه (R27).
 *
 * ─── چرا این صفحه این‌قدر پرحرف است ────────────────────────────────────────
 * این تنها پیامکی است که سامانه جز کدِ ورود می‌فرستد، سهمیه‌اش ماهی یکی
 * است و **برگشت‌پذیر نیست**. پس پیش از کلیک، مدیر باید دقیقاً بداند چند
 * نفر، چه متنی، و چرا. دکمه‌ای که بی‌توضیح یک بار در ماه کار می‌کند،
 * اشتباهِ گران می‌سازد.
 */
export function SmsCampaignPage() {
  const queryClient = useQueryClient()

  useDocumentTitle('پیامک یادآوری شارژ')

  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.smsCampaign.all(),
    queryFn: ({ signal }) => api<CampaignStatus>('/sms-campaign', { signal }),
  })

  const send = useMutation({
    mutationFn: () =>
      api<CampaignStatus & { message: string }>('/sms-campaign', { method: 'POST' }),
    onSuccess: (fresh) => {
      queryClient.setQueryData(queryKeys.smsCampaign.all(), fresh)
      toastSuccess(fresh.message)
    },
    onError: (err) => alertError(err, 'ارسال پیامک ممکن نشد.'),
  })

  if (isLoading) return <InlineSpinner />
  if (error) return <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />
  if (!data) return null

  return (
    <div className="flex flex-col gap-5">
      <header>
        <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
          پیامک یادآوری شارژ
        </h1>
        <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
          ماهی یک بار، فقط برای واحدهای بدهکار — پس از ثبت هزینه‌ها و صدور قبض
        </p>
      </header>

      <Card title={`دوره‌ی ${data.periodLabel}`}>
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
          <Fact label="گیرندگان" value={formatNumber(data.recipientCount)} />
          <Fact label="مجموع بدهی" value={formatMoney(data.totalDebt)} />
          <Fact label="سهمیه‌ی این ماه" value={data.quotaUsed ? 'مصرف‌شده' : 'باقی‌ست'} />
        </div>

        {/* دلیلِ غیرفعال‌بودن همیشه نوشته می‌شود؛ دکمه‌ی خاموشِ بی‌دلیل بی‌فایده است */}
        {data.blockReason && (
          <p
            className="mt-3 flex items-center gap-1.5 rounded-xl px-3 py-2 text-[12.5px]"
            style={{ backgroundColor: 'rgba(245,158,11,0.12)', color: '#b45309' }}
          >
            <TriangleAlert size={14} />
            {data.blockReason}
          </p>
        )}

        {data.preview && (
          <div className="mt-3">
            <p className="mb-1 text-[12px]" style={{ color: 'var(--text-tertiary)' }}>
              متنی که برای هر واحد فرستاده می‌شود:
            </p>
            <p
              className="rounded-xl px-3 py-2 text-[12.5px] leading-6"
              style={{ backgroundColor: 'var(--surface-sunken)', color: 'var(--text-primary)' }}
              dir="rtl"
            >
              {data.preview}
            </p>
          </div>
        )}

        <p
          className="mt-3 flex items-start gap-1.5 text-[11.5px]"
          style={{ color: 'var(--text-tertiary)' }}
        >
          <Info size={13} className="mt-0.5 shrink-0" />
          ساکنی که بدهی ندارد یا خودش این پیامک را خاموش کرده، چیزی دریافت نمی‌کند. پس از ارسال،
          سهمیه‌ی این ماه تمام می‌شود و قابل بازگشت نیست.
        </p>

        <button
          type="button"
          onClick={() => send.mutate()}
          disabled={!data.canSend || data.recipientCount === 0 || send.isPending}
          className="mt-4 flex items-center justify-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-bold text-white disabled:opacity-50"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          {send.isPending ? <Loader2 size={15} className="animate-spin" /> : <Send size={15} />}
          ارسال به {formatNumber(data.recipientCount)} واحد
        </button>
      </Card>

      {data.history.length > 0 && (
        <Card title="ارسال‌های پیشین">
          <ul className="flex flex-col gap-2">
            {data.history.map((row) => (
              <li
                key={row.id}
                className="rounded-xl px-3 py-2.5 text-[12.5px]"
                style={{ backgroundColor: 'var(--surface-sunken)' }}
              >
                <div
                  className="flex flex-wrap items-center gap-x-2"
                  style={{ color: 'var(--text-primary)' }}
                >
                  <span className="font-bold">{row.periodLabel}</span>
                  <span
                    className="flex items-center gap-1"
                    style={{ color: 'var(--text-tertiary)' }}
                  >
                    <CheckCircle2 size={12} />
                    {formatNumber(row.delivered)} از {formatNumber(row.recipients)}
                  </span>
                  {row.failed > 0 && (
                    <span style={{ color: 'var(--color-danger)' }}>
                      {formatNumber(row.failed)} ناموفق
                    </span>
                  )}
                  {row.sentBy && (
                    <span style={{ color: 'var(--text-tertiary)' }}>· {row.sentBy}</span>
                  )}
                  <span style={{ color: 'var(--text-tertiary)' }}>· {row.sentAt}</span>
                </div>

                {/* متنِ واقعیِ ارسال‌شده نگه داشته می‌شود، نه قالبِ امروز */}
                <p className="mt-1 leading-6" style={{ color: 'var(--text-secondary)' }}>
                  {row.template}
                </p>
              </li>
            ))}
          </ul>
        </Card>
      )}
    </div>
  )
}

function Fact({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-xl px-3 py-2" style={{ backgroundColor: 'var(--surface-sunken)' }}>
      <p className="text-[11px]" style={{ color: 'var(--text-tertiary)' }}>
        {label}
      </p>
      <p className="mt-0.5 font-bold" style={{ color: 'var(--text-primary)' }}>
        {value}
      </p>
    </div>
  )
}
