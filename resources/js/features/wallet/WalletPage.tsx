import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { motion } from 'framer-motion'
import { ArrowDownLeft, ArrowUpRight, Wallet } from 'lucide-react'
import { Card } from '@/shared/ui/Card'
import { EmptyState, ErrorState } from '@/shared/ui/PageState'
import { CardListSkeleton, TableSkeleton } from '@/shared/ui/Skeleton'
import { api } from '@/shared/lib/api'
import { errorMessage } from '@/shared/lib/queryClient'
import { queryKeys } from '@/shared/lib/queryKeys'
import { useDocumentTitle } from '@/shared/hooks'
import { formatMoney } from '@/shared/lib/format'

interface WalletSummary {
  unitId: number
  unitLabel: string
  balance: number
}

interface WalletTransaction {
  id: number
  direction: 'credit' | 'debit'
  amount: number
  balanceAfter: number
  sourceLabel: string
  note: string | null
  date: string
}

/**
 * کیفِ پولِ واحد (R22).
 *
 * مانده هیچ‌جا ذخیره نمی‌شود و سرور آن را از جمعِ دفتر می‌دهد؛ پس این صفحه
 * هیچ محاسبه‌ای نمی‌کند و فقط نشان می‌دهد. اگر روزی عددی اینجا با
 * صورت‌حساب نخواند، مشکل در دفتر است نه در رابط.
 */
export function WalletPage() {
  const [selected, setSelected] = useState<number | null>(null)

  useDocumentTitle('کیف پول')

  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.wallet.all(),
    queryFn: ({ signal }) => api<{ wallets: WalletSummary[] }>('/wallet', { signal }),
  })

  const wallets = data?.wallets ?? []
  // تا وقتی کاربر انتخابی نکرده، صورت‌حسابِ اولین واحد نشان داده می‌شود
  const activeUnit = selected ?? wallets[0]?.unitId ?? null

  if (isLoading) return <CardListSkeleton items={2} />
  if (error) return <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />

  return (
    <div className="flex flex-col gap-5">
      <header>
        <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
          کیف پول
        </h1>
        <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
          موجودی هر واحد و گردش آن
        </p>
      </header>

      {wallets.length === 0 ? (
        <EmptyState message="واحدی برای نمایش وجود ندارد." />
      ) : (
        <>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {wallets.map((wallet, index) => (
              <BalanceCard
                key={wallet.unitId}
                wallet={wallet}
                active={wallet.unitId === activeUnit}
                onSelect={() => setSelected(wallet.unitId)}
                delay={Math.min(index * 0.04, 0.3)}
              />
            ))}
          </div>

          {activeUnit !== null && <Statement unitId={activeUnit} />}
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
        {wallet.unitLabel}
      </span>
      <span
        className="text-lg font-extrabold tabular-nums"
        style={{ color: 'var(--text-primary)' }}
      >
        {formatMoney(wallet.balance)}
      </span>
    </motion.button>
  )
}

function Statement({ unitId }: { unitId: number }) {
  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.wallet.statement(unitId),
    queryFn: ({ signal }) =>
      api<{ balance: number; transactions: WalletTransaction[] }>(`/wallet/${unitId}`, { signal }),
  })

  if (isLoading) return <TableSkeleton rows={5} columns={4} />
  if (error) return <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />
  if (!data) return null

  return (
    <Card title="گردش کیف پول" subtitle="۵۰ تراکنش اخیر">
      {data.transactions.length === 0 ? (
        <EmptyState
          message="هنوز تراکنشی ثبت نشده است."
          hint="با پرداخت قبض یا شارژ کیف، اینجا پر می‌شود."
        />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[560px] text-right text-[13px]">
            <thead>
              <tr style={{ color: 'var(--text-tertiary)' }}>
                <th className="pb-3 font-medium">تاریخ</th>
                <th className="pb-3 font-medium">شرح</th>
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
                    {formatMoney(row.balanceAfter)}
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
