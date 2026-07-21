import { Cell, Legend, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts'
import { formatNumber } from '@/lib/format'
import type { BillStatus } from '@/types'

const STATUS_META: Record<BillStatus, { label: string; color: string }> = {
  paid: { label: 'تسویه‌شده', color: 'var(--state-success)' },
  partial: { label: 'پرداخت جزئی', color: 'var(--color-accent-500)' },
  pending: { label: 'در انتظار تایید', color: 'var(--state-info)' },
  unpaid: { label: 'پرداخت‌نشده', color: 'var(--color-danger)' },
}

export function PaymentStatusChart({ counts }: { counts: Record<BillStatus, number> }) {
  const data = (Object.keys(STATUS_META) as BillStatus[])
    .map((key) => ({ key, name: STATUS_META[key].label, value: counts[key] ?? 0 }))
    .filter((row) => row.value > 0)

  const total = data.reduce((sum, row) => sum + row.value, 0)

  if (total === 0) {
    return (
      <p className="py-14 text-center text-sm" style={{ color: 'var(--text-tertiary)' }}>
        برای دوره‌ی جاری قبضی صادر نشده است.
      </p>
    )
  }

  return (
    <div className="relative h-64 w-full" dir="ltr">
      <ResponsiveContainer width="100%" height="100%">
        <PieChart>
          <Pie
            data={data}
            dataKey="value"
            nameKey="name"
            innerRadius="58%"
            outerRadius="82%"
            paddingAngle={2}
            stroke="none"
          >
            {data.map((row) => (
              <Cell key={row.key} fill={STATUS_META[row.key].color} />
            ))}
          </Pie>

          <Tooltip
            contentStyle={{
              backgroundColor: 'var(--surface-overlay)',
              border: '1px solid var(--border-subtle)',
              borderRadius: 12,
              fontFamily: 'Vazirmatn',
              fontSize: 12,
              direction: 'rtl',
            }}
            formatter={(value, name) => [`${formatNumber(Number(value ?? 0))} قبض`, String(name)]}
          />
          <Legend
            wrapperStyle={{ fontFamily: 'Vazirmatn', fontSize: 12, direction: 'rtl' }}
            iconType="circle"
            iconSize={8}
          />
        </PieChart>
      </ResponsiveContainer>

      {/* مجموع در مرکز دونات */}
      <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center pb-8">
        <span className="text-2xl font-extrabold tabular-nums" style={{ color: 'var(--text-primary)' }}>
          {formatNumber(total)}
        </span>
        <span className="text-[11px]" style={{ color: 'var(--text-tertiary)' }}>
          کل قبوض دوره
        </span>
      </div>
    </div>
  )
}
