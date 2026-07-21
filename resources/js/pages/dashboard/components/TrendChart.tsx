import {
  Area,
  AreaChart,
  CartesianGrid,
  Legend,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { formatCompactMoney, formatMoney } from '@/lib/format'

interface TrendPoint {
  label: string
  income: number
  expense: number
}

/**
 * روند درآمد و هزینه‌ی شش ماه اخیر.
 *
 * رنگ‌ها از متغیرهای پالت خوانده می‌شوند نه مقدار ثابت، تا نمودار هم با
 * برند صفحه‌ی اصلی و هم با تم روشن/تاریک هماهنگ بماند.
 */
export function TrendChart({ data, currency }: { data: TrendPoint[]; currency: string }) {
  return (
    <div className="h-72 w-full" dir="ltr">
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart data={data} margin={{ top: 8, right: 8, left: 8, bottom: 0 }}>
          <defs>
            <linearGradient id="incomeFill" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor="var(--color-brand-400)" stopOpacity={0.35} />
              <stop offset="100%" stopColor="var(--color-brand-400)" stopOpacity={0.02} />
            </linearGradient>
            <linearGradient id="expenseFill" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor="var(--color-accent-500)" stopOpacity={0.3} />
              <stop offset="100%" stopColor="var(--color-accent-500)" stopOpacity={0.02} />
            </linearGradient>
          </defs>

          <CartesianGrid strokeDasharray="3 3" stroke="var(--border-subtle)" vertical={false} />

          <XAxis
            dataKey="label"
            reversed
            tick={{ fill: 'var(--text-tertiary)', fontSize: 11, fontFamily: 'Vazirmatn' }}
            tickLine={false}
            axisLine={{ stroke: 'var(--border-subtle)' }}
          />
          <YAxis
            orientation="right"
            tickFormatter={formatCompactMoney}
            tick={{ fill: 'var(--text-tertiary)', fontSize: 11, fontFamily: 'Vazirmatn' }}
            tickLine={false}
            axisLine={false}
            width={62}
          />

          <Tooltip
            contentStyle={{
              backgroundColor: 'var(--surface-overlay)',
              border: '1px solid var(--border-subtle)',
              borderRadius: 12,
              fontFamily: 'Vazirmatn',
              fontSize: 12,
              direction: 'rtl',
            }}
            labelStyle={{ color: 'var(--text-primary)', fontWeight: 700, marginBottom: 4 }}
            formatter={(value, name) => [`${formatMoney(Number(value ?? 0))} ${currency}`, String(name)]}
          />

          <Legend
            wrapperStyle={{ fontFamily: 'Vazirmatn', fontSize: 12, direction: 'rtl' }}
            iconType="circle"
            iconSize={8}
          />

          <Area
            type="monotone"
            dataKey="income"
            name="درآمد"
            stroke="var(--color-brand-500)"
            strokeWidth={2}
            fill="url(#incomeFill)"
          />
          <Area
            type="monotone"
            dataKey="expense"
            name="هزینه"
            stroke="var(--color-accent-500)"
            strokeWidth={2}
            fill="url(#expenseFill)"
          />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  )
}
