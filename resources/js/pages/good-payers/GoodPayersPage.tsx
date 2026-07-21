import { motion } from 'framer-motion'
import { Award, Info } from 'lucide-react'
import { Card } from '@/components/ui/Card'
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/PageState'
import { useApi } from '@/hooks/useApi'
import { useDocumentTitle } from '@/hooks'
import { formatMoney, formatNumber } from '@/lib/format'

interface GoodPayer {
  id: number
  label: string
  floor: number
  onTime: number
  totalPaid: number
  tier: string
}

interface GoodPayersResponse {
  enabled: boolean
  reason: string | null
  currency?: string
  data: GoodPayer[]
}

/** رنگ هر رتبه، از پالت برند و طلایی accent. */
const TIER_COLOR: Record<string, string> = {
  'طلایی': 'var(--color-accent-500)',
  'نقره‌ای': 'var(--text-tertiary)',
  'برنزی': '#a4652f',
}

export function GoodPayersPage() {
  useDocumentTitle('ساکنین خوش‌حساب')

  const { data, error, isLoading, reload } = useApi<GoodPayersResponse>('/good-payers')

  return (
    <div className="flex flex-col gap-5">
      <header>
        <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
          ساکنین خوش‌حساب
        </h1>
        <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
          واحدهایی که قبوضشان را به‌موقع پرداخت کرده‌اند
        </p>
      </header>

      {isLoading && <LoadingState rows={4} />}
      {error && <ErrorState message={error} onRetry={reload} />}

      {data && !isLoading && (
        <Card>
          {!data.enabled ? (
            <div className="flex flex-col items-center gap-2 py-12 text-center">
              <Info size={26} style={{ color: 'var(--text-tertiary)' }} />
              <p className="text-sm" style={{ color: 'var(--text-secondary)' }}>
                {data.reason}
              </p>
              <p className="text-xs" style={{ color: 'var(--text-tertiary)' }}>
                از «تنظیمات مجتمع» می‌توانید این بخش را فعال کنید.
              </p>
            </div>
          ) : data.data.length === 0 ? (
            <EmptyState
              message="هنوز داده‌ای برای رتبه‌بندی موجود نیست."
              hint="پس از ثبت چند پرداخت به‌موقع، فهرست اینجا نمایش داده می‌شود."
            />
          ) : (
            <ul className="flex flex-col gap-2">
              {data.data.map((payer, index) => (
                <motion.li
                  key={payer.id}
                  initial={{ opacity: 0, x: 10 }}
                  animate={{ opacity: 1, x: 0 }}
                  transition={{ duration: 0.3, delay: Math.min(index * 0.04, 0.35) }}
                  className="flex items-center justify-between rounded-xl px-4 py-3"
                  style={{ backgroundColor: 'var(--surface-sunken)' }}
                >
                  <div className="flex items-center gap-3">
                    <span
                      className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-[13px] font-bold"
                      style={{
                        backgroundColor: `color-mix(in srgb, ${TIER_COLOR[payer.tier] ?? 'var(--color-brand-500)'} 18%, transparent)`,
                        color: TIER_COLOR[payer.tier] ?? 'var(--color-brand-600)',
                      }}
                    >
                      {formatNumber(index + 1)}
                    </span>

                    <div>
                      <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
                        {payer.label}
                      </p>
                      <p className="text-[11px]" style={{ color: 'var(--text-tertiary)' }}>
                        طبقه {formatNumber(payer.floor)} · مجموع پرداخت {formatMoney(payer.totalPaid)}{' '}
                        {data.currency}
                      </p>
                    </div>
                  </div>

                  <div className="flex items-center gap-3">
                    <span className="text-[11px]" style={{ color: 'var(--text-tertiary)' }}>
                      {formatNumber(payer.onTime)} پرداخت به‌موقع
                    </span>
                    <span
                      className="flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px] font-medium"
                      style={{
                        backgroundColor: `color-mix(in srgb, ${TIER_COLOR[payer.tier] ?? 'var(--color-brand-500)'} 15%, transparent)`,
                        color: TIER_COLOR[payer.tier] ?? 'var(--color-brand-600)',
                      }}
                    >
                      <Award size={11} />
                      {payer.tier}
                    </span>
                  </div>
                </motion.li>
              ))}
            </ul>
          )}
        </Card>
      )}
    </div>
  )
}
