import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { motion } from 'framer-motion'
import { ArrowDownLeft, ArrowUpRight, Wallet } from 'lucide-react'
import { Card } from '@/shared/ui/Card'
import { EmptyState, ErrorState } from '@/shared/ui/PageState'
import { CardListSkeleton, TableSkeleton } from '@/shared/ui/Skeleton'
import { api } from '@/shared/lib/api'
import { queryKeys } from '@/shared/lib/queryKeys'
import { useDocumentTitle } from '@/shared/hooks'
import { formatMoney } from '@/shared/lib/format'

/**
 * کیفِ خودِ کاربر (یکی برای هر حساب) یا گردشِ یک عضو برای مدیر. مانده‌ی عضو
 * `null` است: مدیر کیفِ شخصیِ دیگران را نمی‌بیند، فقط ردیف‌های همین مجتمع را.
 */
interface WalletSummary {
  key: string
  label: string
  balance: number | null
  statement: string
}

interface WalletTransaction {
  id: number
  direction: 'credit' | 'debit'
  amount: number
  balanceAfter: number | null
  complexName: string | null
  sourceLabel: string
  note: string | null
  date: string
}

/**
 * کیفِ پولِ **شخصی** (R22؛ یک کیف برای هر حساب — مرحلهٔ ۹، تصمیمِ کارفرما).
 *
 * یک مانده در هر داشبورد؛ پس از پایانِ عضویت هم همین صفحه (در داشبوردِ اولیه)
 * آن را نشان می‌دهد. مدیر گردشِ اعضا را **فقط در همین مجتمع و بی مانده** می‌بیند.
 * مانده از سرور و از جمعِ دفتر می‌آید؛ این صفحه چیزی حساب نمی‌کند.
 */
export function WalletPage() {
  const [selected, setSelected] = useState<string | null>(null)

  useDocumentTitle('کیف پول')

  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.wallet.all(),
    queryFn: ({ signal }) =>
      api<{ wallets: WalletSummary[]; members?: WalletSummary[] }>('/wallet', { signal }),
  })

  const own = data?.wallets ?? []
  const members = data?.members ?? []
  const all = [...own, ...members]
  // تا وقتی کاربر انتخابی نکرده، صورت‌حسابِ اولین جیب نشان داده می‌شود
  const active = all.find((wallet) => wallet.key === selected) ?? all[0] ?? null

  if (isLoading) return <CardListSkeleton items={2} />
  if (error) return <ErrorState error={error} onRetry={() => void refetch()} />

  return (
    <div className="flex flex-col gap-5">
      <header>
        <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
          کیف پول
        </h1>
        <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
          یک کیفِ شخصی، با همین مانده در همه‌ی مجتمع‌هایتان
        </p>
      </header>

      {all.length === 0 ? (
        <EmptyState message="کیفِ پولِ شما هنوز گردشی ندارد." />
      ) : (
        <>
          {own.length > 0 && (
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
              {own.map((wallet, index) => (
                <BalanceCard
                  key={wallet.key}
                  wallet={wallet}
                  active={wallet.key === active?.key}
                  onSelect={() => setSelected(wallet.key)}
                  delay={Math.min(index * 0.04, 0.3)}
                />
              ))}
            </div>
          )}

          {members.length > 0 && (
            <section className="flex flex-col gap-3">
              <h2 className="text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
                گردشِ کیفِ اعضا در این مجتمع
              </h2>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {members.map((wallet, index) => (
                  <BalanceCard
                    key={wallet.key}
                    wallet={wallet}
                    active={wallet.key === active?.key}
                    onSelect={() => setSelected(wallet.key)}
                    delay={Math.min(index * 0.04, 0.3)}
                  />
                ))}
              </div>
            </section>
          )}

          {active !== null && <Statement wallet={active} />}
        </>
      )}
    </div>
  )
}

function BalanceCard({
  wallet,
  active,
  onSelect,
  delay,
}: {
  wallet: WalletSummary
  active: boolean
  onSelect: () => void
  delay: number
}) {
  return (
    <motion.button
      type="button"
      onClick={onSelect}
      initial={{ opacity: 0, y: 8 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.25, delay }}
      className="flex flex-col gap-2 rounded-2xl border p-4 text-right transition-colors"
      style={{
        borderColor: active ? 'var(--color-brand-500)' : 'var(--border-default)',
        backgroundColor: 'var(--surface-base)',
      }}
      aria-pressed={active}
    >
      <span
        className="flex items-center gap-2 text-[13px]"
        style={{ color: 'var(--text-tertiary)' }}
      >
        <Wallet size={15} style={{ color: 'var(--color-brand-500)' }} />
        {wallet.label}
      </span>
      <span
        className="text-lg font-extrabold tabular-nums"
        style={{ color: 'var(--text-primary)' }}
      >
        {wallet.balance === null ? 'گردش در همین مجتمع' : formatMoney(wallet.balance)}
      </span>
    </motion.button>
  )
}

function Statement({ wallet }: { wallet: WalletSummary }) {
  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.wallet.statement(wallet.key),
    queryFn: ({ signal }) =>
      api<{ balance: number; transactions: WalletTransaction[] }>(wallet.statement, { signal }),
  })

  if (isLoading) return <TableSkeleton rows={5} columns={4} />
  if (error) return <ErrorState error={error} onRetry={() => void refetch()} />
  if (!data) return null

  return (
    <Card title="گردش کیف پول" subtitle="۵۰ تراکنش اخیر">
      {data.transactions.length === 0 ? (
        <EmptyState
          message="هنوز تراکنشی ثبت نشده است."
          hint="مازادِ پرداخت‌ها اینجا می‌نشیند و قبض‌های بعدی از آن پرداخت می‌شوند."
        />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[560px] text-right text-[13px]">
            <thead>
              <tr style={{ color: 'var(--text-tertiary)' }}>
                <th className="pb-3 font-medium">تاریخ</th>
                <th className="pb-3 font-medium">شرح</th>
                <th className="pb-3 font-medium">مجتمع</th>
                <th className="pb-3 font-medium">مبلغ</th>
                <th className="pb-3 font-medium">مانده</th>
              </tr>
            </thead>
            <tbody>
              {data.transactions.map((row) => (
                <tr
                  key={row.id}
                  className="border-t"
                  style={{ borderColor: 'var(--border-subtle)' }}
                >
                  <td className="py-3 tabular-nums" style={{ color: 'var(--text-secondary)' }}>
                    {row.date}
                  </td>
                  <td className="py-3" style={{ color: 'var(--text-primary)' }}>
                    {row.sourceLabel}
                    {row.note && (
                      <span className="block text-xs" style={{ color: 'var(--text-tertiary)' }}>
                        {row.note}
                      </span>
                    )}
                  </td>
                  <td className="py-3" style={{ color: 'var(--text-secondary)' }}>
                    {row.complexName ?? '—'}
                  </td>
                  <td className="py-3">
                    {/* جهت با رنگ و آیکون هم نشان داده می‌شود، نه فقط با علامت */}
                    <span
                      className="inline-flex items-center gap-1 font-semibold tabular-nums"
                      style={{
                        color:
                          row.direction === 'credit'
                            ? 'var(--state-success)'
                            : 'var(--color-danger)',
                      }}
                    >
                      {row.direction === 'credit' ? (
                        <ArrowDownLeft size={13} />
                      ) : (
                        <ArrowUpRight size={13} />
                      )}
                      {formatMoney(row.amount)}
                    </span>
                  </td>
                  <td className="py-3 tabular-nums" style={{ color: 'var(--text-secondary)' }}>
                    {row.balanceAfter === null ? '—' : formatMoney(row.balanceAfter)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  )
}
